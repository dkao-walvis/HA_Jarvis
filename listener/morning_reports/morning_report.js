#!/usr/bin/env node
/**
 * Morning Report — fetches weather + news, summarizes via Ollama, sends to WhatsApp
 * Cron: 0 5 * * * /usr/bin/node /home/tulong/.config/tulong-agent/morning_report.js
 */

const https = require('https');
const http  = require('http');
const fs    = require('fs');
const path  = require('path');
const { execFileSync } = require('child_process');

const REMINDERS_DB = '/home/darren/.openclaw/system/reminders.db';

// ── Config ────────────────────────────────────────────────────────────────────
const ENV_FILE = path.join(__dirname, '.env');
const env = {};
fs.readFileSync(ENV_FILE, 'utf8').split('\n').forEach(line => {
    const [k, ...v] = line.split('=');
    if (k && v.length) env[k.trim()] = v.join('=').trim();
});

// ── Load jarvis-brain DB credentials ──────────────────────────────────────────
const brainEnvPath = env.JARVIS_BRAIN_ENV || '/home/darren/projects/jarvis-brain/.env';
const brainEnv = {};
try {
    fs.readFileSync(brainEnvPath, 'utf8').split('\n').forEach(line => {
        const [k, ...v] = line.split('=');
        if (k && v.length) brainEnv[k.trim()] = v.join('=').trim();
    });
} catch (e) {
    log(`Warning: could not read ${brainEnvPath}: ${e.message}`);
}

const GNEWS_KEY     = env.GNEWS_API_KEY;
const GUARDIAN_KEY  = env.GUARDIAN_API_KEY || 'test';
const ASK_CLAUDE_URL = env.ASK_CLAUDE_URL || 'http://100.69.36.45:3000/ask_claude';
const TELEGRAM_TOKEN   = env.TELEGRAM_BOT_TOKEN;
const TELEGRAM_CHAT_ID = env.TELEGRAM_CHAT_ID;
const HA_JARVIS_API_URL = env.HA_JARVIS_API_URL || 'http://localhost/HA_Jarvis/api.php';
const HA_JARVIS_API_KEY = env.HA_JARVIS_API_KEY;
const HA_JARVIS_CALENDAR_HINT = env.HA_JARVIS_CALENDAR_HINT || 'josiexkao@gmail.com';
const REPORT_TIMEZONE = env.REPORT_TIMEZONE || 'America/Toronto';
const ASHBURY_BELL_HHMM      = env.ASHBURY_BELL_HHMM || '08:15';
const ASHBURY_LEAVE_BUFFER_MIN = parseInt(env.ASHBURY_LEAVE_BUFFER_MIN || '15', 10);

const SYSTEM_PROMPT = `You are a friendly morning briefing assistant for someone in Ottawa, Canada. Based on the data below, write a concise, friendly morning briefing.

Structure:
- Weather: 1-2 sentences. Current temp, today's high/low, and the rain window if one is provided (otherwise skip rain). Include a weather alert only if one is in the data.
- Gas: 1 short sentence. Today's Ottawa average and whether tomorrow is up, down, or unchanged. If the two prices are equal, say "holding steady" — do NOT say "down to" or "up to" the same number.
- School commute: if a "School commute" block is provided, 1 sentence summarizing the Waze time to Ashbury and the leave-by target. If no such block is present, skip this section entirely.
- Household: if a "Household Anomalies" block is provided, include 1-3 bullets for the most important items. Say "quiet overnight" if the block says none. Skip if the block is missing entirely.
- Agenda: always include. If the agenda data contains bullet lines, list them verbatim. If it literally says "(no events today)", write exactly: "Agenda: Nothing scheduled today." If it says "(unavailable: ...)", write exactly: "Agenda: Calendar unavailable."
- News sections in order: Ottawa, Canada, World, Tech, Business. Highlight the most interesting story or two per section. Skip a section if it has no relevant stories.
- End with a one-line motivational note for the day.

Keep it under 260 words total. Warm, conversational tone, second person ("your", "you") when addressing Darren. Do not refer to Darren in the third person. Do not include emoji prefixes unless they add clarity.`;

// ── Helpers ───────────────────────────────────────────────────────────────────
function get(url, headers = {}) {
    return new Promise((resolve, reject) => {
        const mod = url.startsWith('https') ? https : http;
        const req = mod.get(url, { headers }, res => {
            let data = '';
            res.on('data', d => data += d);
            res.on('end', () => resolve(data));
        });
        req.on('error', reject);
    });
}

function post(url, body, headers = {}) {
    return new Promise((resolve, reject) => {
        const u   = new URL(url);
        const mod = u.protocol === 'https:' ? https : http;
        const payload = JSON.stringify(body);
        const req = mod.request({
            hostname: u.hostname,
            port:     u.port || (u.protocol === 'https:' ? 443 : 80),
            path:     u.pathname,
            method:   'POST',
            headers:  { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload), ...headers },
        }, res => {
            let data = '';
            res.on('data', d => data += d);
            res.on('end', () => resolve(data));
        });
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

function log(msg) { console.log(`[${new Date().toISOString()}] ${msg}`); }

function parseJson(label, raw) {
    try {
        return JSON.parse(raw);
    } catch (err) {
        const preview = String(raw).slice(0, 120).replace(/\s+/g, ' ');
        throw new Error(`${label} returned invalid JSON: ${preview}`);
    }
}

function normalizeCalendarHint(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '');
}

function formatLocalTime(dateLike, timeZone = REPORT_TIMEZONE) {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(dateLike));
}

function startOfTodayInTimezone(timeZone = REPORT_TIMEZONE) {
    const now = new Date();
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const map = Object.fromEntries(parts.filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
    return `${map.year}-${map.month}-${map.day}`;
}

function addDaysToDateKey(dateKey, days) {
    const date = new Date(`${dateKey}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

function eventDateKey(event, timeZone = REPORT_TIMEZONE) {
    const start = event?.start;
    // HA get_events returns start as a plain date/datetime string
    const dateTime = typeof start === 'string' ? start : (start?.dateTime || null);
    const dateOnly = typeof start === 'object' ? start?.date : null;
    if (dateOnly) return dateOnly;
    if (dateTime) {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(new Date(dateTime));
        const map = Object.fromEntries(parts.filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
        return `${map.year}-${map.month}-${map.day}`;
    }
    return null;
}

// ── HA REST helpers ────────────────────────────────────────────────────────────
const VAULT_TOKEN_FOR_HA = env.VAULT_TOKEN || '184e36fd5df9423621dd3179a0a93d59dba3e168512aa1950f70310d688f21b1';
const VAULT_URL          = env.VAULT_URL || 'http://100.69.36.45:8800/api/secrets';
const HA_BASE_URL        = env.HA_BASE_URL || 'http://homeassistant:8123';

let _cachedHaToken = null;
async function getHaToken() {
    if (_cachedHaToken) return _cachedHaToken;
    const raw = await get(`${VAULT_URL}/openclaw_to_ha`, { 'Authorization': `Bearer ${VAULT_TOKEN_FOR_HA}` });
    const parsed = parseJson('vault', raw);
    if (!parsed.value) throw new Error('vault returned no HA token');
    _cachedHaToken = parsed.value;
    return _cachedHaToken;
}

async function haGetState(entityId) {
    const token = await getHaToken();
    const raw = await get(`${HA_BASE_URL}/api/states/${entityId}`, { 'Authorization': `Bearer ${token}` });
    return parseJson(`HA state ${entityId}`, raw);
}

async function haCallService(domain, service, data, returnResponse = false) {
    const token = await getHaToken();
    const url = `${HA_BASE_URL}/api/services/${domain}/${service}${returnResponse ? '?return_response=true' : ''}`;
    const body = await post(url, data, { 'Authorization': `Bearer ${token}` });
    return parseJson(`HA service ${domain}.${service}`, body);
}

// ── Fetch weather (v2 — hourly forecast via HA) ───────────────────────────────
async function fetchWeatherV2() {
    log('Fetching weather forecast from HA...');
    try {
        const state = await haGetState('weather.ottawa_forecast');
        const currentTemp = state?.attributes?.temperature;
        const currentCondition = state?.state;

        const forecastResp = await haCallService('weather', 'get_forecasts',
            { entity_id: 'weather.ottawa_forecast', type: 'hourly' }, true);
        const hourly = forecastResp?.service_response?.['weather.ottawa_forecast']?.forecast || [];

        if (!hourly.length) {
            return `Weather in Ottawa:\nNow ${currentTemp}°C ${currentCondition}.\n(hourly forecast unavailable)`;
        }

        // Next 18 hours
        const window = hourly.slice(0, 18);
        const tz = REPORT_TIMEZONE;

        const temps = window.map(h => h.temperature).filter(t => t != null);
        const high = Math.max(...temps);
        const low = Math.min(...temps);
        const peakHour = window.find(h => h.temperature === high);
        const peakTimeStr = peakHour
            ? new Date(peakHour.datetime).toLocaleTimeString('en-US', { timeZone: tz, hour: 'numeric' })
            : '';

        // Rain window: first and last hour with probability >= 50
        let rainStart = null, rainEnd = null;
        for (const h of window) {
            if ((h.precipitation_probability || 0) >= 50) {
                if (!rainStart) rainStart = h;
                rainEnd = h;
            }
        }
        const fmtHour = dt => new Date(dt).toLocaleTimeString('en-US', { timeZone: tz, hour: 'numeric' });

        const lines = [
            'Weather in Ottawa:',
            `Now ${currentTemp}°C ${currentCondition}.`,
            `Today: high ${Math.round(high)}°C around ${peakTimeStr}, low ${Math.round(low)}°C.`,
        ];
        if (rainStart) {
            const peakProb = Math.max(...window.filter(h => (h.precipitation_probability || 0) >= 50).map(h => h.precipitation_probability));
            lines.push(`Rain: ${fmtHour(rainStart.datetime)} – ${fmtHour(rainEnd.datetime)} (${peakProb}% peak).`);
        }
        return lines.join('\n');
    } catch (err) {
        log(`Weather fetch failed: ${err.message}`);
        return 'Weather in Ottawa:\n(unavailable)';
    }
}

async function haJarvisGet(params) {
    const url = new URL(HA_JARVIS_API_URL);
    for (const [key, value] of Object.entries(params)) {
        url.searchParams.set(key, String(value));
    }
    const raw = await get(url.toString());
    return parseJson('HA_Jarvis GET', raw);
}

async function haJarvisPost(body) {
    const raw = await post(HA_JARVIS_API_URL, body);
    return parseJson('HA_Jarvis POST', raw);
}

function extractEvents(node) {
    if (!node) return [];
    if (Array.isArray(node)) {
        for (const item of node) {
            const events = extractEvents(item);
            if (events.length) return events;
        }
        return [];
    }
    if (typeof node !== 'object') return [];
    if (Array.isArray(node.events)) return node.events;
    for (const value of Object.values(node)) {
        const events = extractEvents(value);
        if (events.length) return events;
    }
    return [];
}

async function resolveCalendarEntity() {
    const listing = await haJarvisGet({ action: 'list', api_key: HA_JARVIS_API_KEY });
    const entities = listing.entities || [];
    const hint = normalizeCalendarHint(HA_JARVIS_CALENDAR_HINT);

    const match = entities.find(entity => {
        const name = normalizeCalendarHint(entity.friendly_name);
        const id = normalizeCalendarHint(entity.entity_id);
        return entity.entity_id.startsWith('calendar.') && (name.includes(hint) || id.includes(hint));
    });

    return match?.entity_id || null;
}

// ── Fetch CRM follow-ups scheduled for today ────────────────────────────────
function fetchCrmFollowupsForToday(timeZone = REPORT_TIMEZONE) {
    try {
        if (!fs.existsSync(REMINDERS_DB)) return [];
        // Compute today's local-day window as UTC bounds, since execute_at is stored in UTC
        const todayKey = startOfTodayInTimezone(timeZone); // YYYY-MM-DD local
        const startLocal = new Date(`${todayKey}T00:00:00`);
        const tzOffsetMin = getTimezoneOffsetMinutes(timeZone, startLocal);
        const startUtc = new Date(Date.parse(`${todayKey}T00:00:00Z`) - tzOffsetMin * 60000);
        const endUtc   = new Date(startUtc.getTime() + 24 * 60 * 60 * 1000);
        const fmt = d => d.toISOString().slice(0, 19).replace('T', ' ');

        const out = execFileSync('sqlite3', [
            '-separator', '\t',
            REMINDERS_DB,
            `SELECT execute_at, message FROM reminders
             WHERE active = 1 AND agent = 'crm'
               AND execute_at >= '${fmt(startUtc)}' AND execute_at < '${fmt(endUtc)}'
             ORDER BY execute_at`
        ], { timeout: 5000 }).toString().trim();

        if (!out) return [];
        return out.split('\n').map(line => {
            const [execAt, ...rest] = line.split('\t');
            const msg = rest.join('\t');
            const localTime = formatLocalTime(execAt.replace(' ', 'T') + 'Z', timeZone);
            return `- ${localTime} — ${msg}`;
        });
    } catch (err) {
        log(`CRM follow-up lookup failed: ${err.message}`);
        return [];
    }
}

function getTimezoneOffsetMinutes(timeZone, date) {
    // Offset of `timeZone` relative to UTC at `date`, in minutes (positive = ahead of UTC).
    const dtf = new Intl.DateTimeFormat('en-US', {
        timeZone, hour12: false,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
    const parts = Object.fromEntries(dtf.formatToParts(date).filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
    const asUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
    return (asUtc - date.getTime()) / 60000;
}

// ── Fetch agenda via HA_Jarvis calendar entity ──────────────────────────────
async function fetchTodayAgenda() {
    const crmLines = fetchCrmFollowupsForToday(REPORT_TIMEZONE);
    const finish = (calendarLines, unavailableNote) => {
        const parts = [];
        if (calendarLines && calendarLines.length) parts.push(...calendarLines);
        else if (unavailableNote) parts.push(unavailableNote);
        if (crmLines.length) parts.push(...crmLines);
        if (!parts.length) return "Today's Agenda:\n(no events today)\n";
        return `Today's Agenda:\n${parts.join('\n')}\n`;
    };

    if (!HA_JARVIS_API_KEY) {
        return finish([], '(calendar unavailable: HA_JARVIS_API_KEY missing)');
    }

    log('Fetching today agenda via HA_Jarvis...');
    const calendarEntity = await resolveCalendarEntity();
    if (!calendarEntity) {
        return finish([], `(calendar unavailable: no entity matched ${HA_JARVIS_CALENDAR_HINT})`);
    }

    let items = [];
    try {
        const eventsResp = await haJarvisPost({
            action: 'call',
            entity_id: calendarEntity,
            service: 'get_events',
            return_response: true,
            data: {
                duration: { hours: 48 },
            },
            api_key: HA_JARVIS_API_KEY,
        });
        items = extractEvents(eventsResp.result);
    } catch (err) {
        log(`Agenda call fallback to state lookup: ${err.message}`);
    }

    if (!items.length) {
        const stateResp = await haJarvisGet({
            action: 'get',
            entity_id: calendarEntity,
            api_key: HA_JARVIS_API_KEY,
        });
        const entity = stateResp.entity || {};
        const attrs = entity.attributes || {};
        if (attrs.message || attrs.start_time || attrs.start_date_time) {
            items = [{
                summary: attrs.message || entity.state || '(calendar event)',
                start: { dateTime: attrs.start_time || attrs.start_date_time || null, date: attrs.start_date || null },
            }];
        }
    }

    const todayKey = startOfTodayInTimezone(REPORT_TIMEZONE);
    const todaysItems = items.filter(event => eventDateKey(event, REPORT_TIMEZONE) === todayKey);

    const calendarLines = todaysItems.slice(0, 8).map(event => {
        const startVal = typeof event.start === 'string' ? event.start : (event.start?.dateTime || event.start?.date || null);
        const isAllDay = typeof event.start === 'object' && event.start?.date && !event.start?.dateTime;
        const start = isAllDay ? 'All day' : (startVal ? formatLocalTime(startVal, REPORT_TIMEZONE) : 'Time TBD');
        return `- ${start} — ${event.summary || '(untitled event)'}`;
    });

    return finish(calendarLines, null);
}

// ── Fetch school commute (Waze, gated on input_boolean.school_on) ─────────────
async function fetchSchoolCommute() {
    log('Fetching school commute status...');
    try {
        const schoolOn = await haGetState('input_boolean.school_on');
        if (schoolOn?.state !== 'on') {
            log('school_on is off — skipping commute block');
            return '';
        }
        const waze = await haGetState('sensor.waze_travel_time');
        const minutes = Math.round(parseFloat(waze?.state));
        const route = waze?.attributes?.route || 'normal route';

        // Compute leave-by = bell − buffer
        const [bh, bm] = ASHBURY_BELL_HHMM.split(':').map(Number);
        const bellMinutes = bh * 60 + bm;
        const leaveMinutes = bellMinutes - ASHBURY_LEAVE_BUFFER_MIN;
        const leaveH = Math.floor(leaveMinutes / 60);
        const leaveM = leaveMinutes % 60;
        const leaveBy = `${((leaveH + 11) % 12 + 1)}:${String(leaveM).padStart(2, '0')} ${leaveH < 12 ? 'AM' : 'PM'}`;

        const lines = [
            'School commute:',
            `Current travel time: ${minutes} min to Ashbury (${route}).`,
            `Leave by ${leaveBy} for ${ASHBURY_BELL_HHMM} bell.`,
        ];
        return lines.join('\n');
    } catch (err) {
        log(`School commute fetch failed: ${err.message}`);
        return '';
    }
}

// ── Fetch household anomalies from jarvis-brain event_log ─────────────────────
async function fetchHouseholdAnomalies() {
    log('Fetching household anomalies from jarvis-brain...');
    try {
        const mysql = require('mysql2/promise');
        const conn = await mysql.createConnection({
            host: brainEnv.DB_HOST || 'localhost',
            user: brainEnv.DB_USER,
            password: brainEnv.DB_PASS,
            database: brainEnv.DB_NAME || 'jarvis_brain',
        });
        const [rows] = await conn.execute(
            `SELECT
                DATE_FORMAT(ts, '%l:%i %p') AS time_str,
                camera,
                ai_decision,
                COALESCE(notify_message, ai_reasoning) AS summary
             FROM event_log
             WHERE ts >= NOW() - INTERVAL 24 HOUR
               AND (
                   ai_decision = 'URGENT'
                   OR (ai_decision = 'NOTIFY' AND notified = 1)
               )
             ORDER BY ts ASC
             LIMIT 10`
        );
        await conn.end();
        if (!rows.length) {
            return 'Household Anomalies (last 24h):\nNone — quiet night.';
        }
        const lines = ['Household Anomalies (last 24h):'];
        for (const r of rows) {
            const cam = r.camera ? ` (${r.camera})` : '';
            lines.push(`- ${r.time_str}${cam}: ${r.summary} — ${r.ai_decision}`);
        }
        return lines.join('\n');
    } catch (err) {
        log(`Household anomalies fetch failed: ${err.message}`);
        return 'Household Anomalies (last 24h):\n(unavailable)';
    }
}

// ── Fetch Ottawa gas price via CityNews ──────────────────────────────────────
async function fetchOttawaGasPrice() {
    log('Fetching Ottawa gas price from CityNews...');
    const raw = await get('https://ottawa.citynews.ca/gas-prices/');
    const text = raw
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/&#43;/g, '+')
        .replace(/&#x27;/g, "'")
        .replace(/&amp;/g, '&')
        .replace(/\s+/g, ' ')
        .trim();

    // Match forecast: handles "rise/fall X cent(s) ... to an average of Y" and "remain unchanged ... holding at Y"
    const forecastMatch = text.match(/prices are expected to (rise|fall|drop|remain unchanged)\s+(?:(\d+)\s+cent\(s\)\s+)?at 12:01am on ([A-Za-z]+ \d{1,2}, \d{4})\s+(?:to an average of|holding at an average of)\s+(\d{2,3}\.\d)\s+cent\(s\)\/litre/i);

    if (!forecastMatch) {
        return 'Ottawa Gas Price:\n(unavailable: could not parse Ottawa CityNews gas price page)\n';
    }

    const direction = forecastMatch[1].toLowerCase();
    const predictedDelta = parseFloat(forecastMatch[2] || '0');
    const predictedDate = forecastMatch[3];
    const predictedPrice = parseFloat(forecastMatch[4]);

    // Reverse-compute today's price from the forecast delta
    let todayPrice = predictedPrice;
    if (direction === 'fall' || direction === 'drop') todayPrice = predictedPrice + predictedDelta;
    else if (direction === 'rise') todayPrice = predictedPrice - predictedDelta;
    const todayStr = todayPrice.toFixed(1);
    const tomorrowStr = predictedPrice.toFixed(1);
    const isUnchanged = direction === 'remain unchanged' || predictedDelta === 0 || todayStr === tomorrowStr;

    const lines = ['Ottawa Gas Price:'];
    lines.push(`Today's average: ${todayStr} cents/litre`);
    if (isUnchanged) {
        lines.push(`${predictedDate}: ${tomorrowStr} cents/litre (unchanged — same as today)`);
    } else {
        lines.push(`${predictedDate}: ${direction} ${predictedDelta} cent(s) to ${tomorrowStr} cents/litre`);
    }
    lines.push(`Trend: ${isUnchanged ? 'flat (no change)' : direction}`);
    lines.push('Source: Ottawa CityNews gas prices');
    lines.push('Reference: https://ottawa.citynews.ca/gas-prices/');

    return lines.join('\n') + '\n';
}

// ── Fetch GNews ───────────────────────────────────────────────────────────────
async function fetchGNews(label, url) {
    log(`Fetching GNews: ${label}...`);
    const raw     = await get(url);
    const data    = parseJson(`GNews ${label}`, raw);
    const articles = (data.articles || []).slice(0, 5);
    if (!articles.length) return `${label}:\n(no articles)\n`;
    const lines = articles.map(a => `- ${a.title}\n  ${(a.description || '').slice(0, 150)}`);
    return `${label}:\n${lines.join('\n')}\n`;
}

// ── Fetch Guardian ────────────────────────────────────────────────────────────
async function fetchGuardian() {
    log('Fetching Guardian world news...');
    const url = `https://content.guardianapis.com/search?section=world&order-by=newest&show-fields=trailText&page-size=5&api-key=${GUARDIAN_KEY}`;
    const raw = await get(url);
    const parsed   = parseJson('Guardian', raw);
    const results  = (parsed.response && parsed.response.results) ? parsed.response.results : [];
    if (!results.length) return 'Guardian World News:\n(no articles)\n';
    const lines = results.map(function(a) {
        const trail = (a.fields && a.fields.trailText) ? a.fields.trailText : '';
        return `- ${a.webTitle}\n  ${trail.replace(/<[^>]+>/g,'').slice(0,150)}`;
    });
    return `Guardian World News:\n${lines.join('\n')}\n`;
}

// ── Ask local Claude (via jarvis_listener) ─────────────────────────────────────
async function askClaude(content, system) {
    log(`Sending to local Claude (${ASK_CLAUDE_URL})...`);
    const body = await post(ASK_CLAUDE_URL, {
        prompt:  content,
        context: system,
    });
    const parsed = parseJson('ask_claude', body);
    if (parsed.error) throw new Error(`ask_claude error: ${parsed.error}`);
    if (!parsed.reply) throw new Error('ask_claude returned no reply');
    return parsed.reply;
}

// ── Send Telegram ─────────────────────────────────────────────────────────────
async function sendTelegram(message) {
    log(`Sending Telegram to ${TELEGRAM_CHAT_ID}...`);
    const result = await post(`https://api.telegram.org/bot${TELEGRAM_TOKEN}/sendMessage`, {
        chat_id: TELEGRAM_CHAT_ID,
        text:    message,
        parse_mode: 'Markdown',
    });
    log(`Telegram result: ${result}`);
    return result;
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
    log('=== Morning Report starting ===');
    try {
        const [weather, gasPrice, agenda, canada, ottawa, world, tech, business, guardian, commute, anomalies] = await Promise.all([
            fetchWeatherV2(),
            fetchOttawaGasPrice(),
            fetchTodayAgenda(),
            fetchGNews('Canada News',  `https://gnews.io/api/v4/top-headlines?category=general&country=ca&lang=en&max=5&apikey=${GNEWS_KEY}`),
            fetchGNews('Ottawa News',  `https://gnews.io/api/v4/search?q=Ottawa&lang=en&max=5&apikey=${GNEWS_KEY}`),
            fetchGNews('World News',   `https://gnews.io/api/v4/top-headlines?category=world&lang=en&max=5&apikey=${GNEWS_KEY}`),
            fetchGNews('Tech News',    `https://gnews.io/api/v4/top-headlines?category=technology&lang=en&max=5&apikey=${GNEWS_KEY}`),
            fetchGNews('Business News', `https://gnews.io/api/v4/top-headlines?category=business&lang=en&max=5&apikey=${GNEWS_KEY}`),
            fetchGuardian(),
            fetchSchoolCommute(),
            fetchHouseholdAnomalies(),
        ]);

        const sections = [weather, gasPrice, commute, anomalies, agenda, canada, ottawa, world, tech, business, guardian].filter(Boolean);
        const combined = sections.join('\n\n');
        const briefing = await askClaude(combined, SYSTEM_PROMPT);

        const dateHeader = new Intl.DateTimeFormat('en-US', {
            timeZone: REPORT_TIMEZONE,
            weekday: 'long',
            month: 'long',
            day: 'numeric',
        }).format(new Date());
        const finalMessage = `*Today is ${dateHeader}*\n\n${briefing}`;

        log('Briefing ready:\n' + finalMessage);
        await sendTelegram(finalMessage);
        log('=== Morning Report done ===');
    } catch (err) {
        log(`ERROR: ${err.message}`);
        process.exit(1);
    }
}

main();
