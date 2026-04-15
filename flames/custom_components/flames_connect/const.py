DOMAIN = "flames_connect"

TENANT       = "gdhvb2cflameconnect"
CLIENT_ID    = "1af761dc-085a-411f-9cb9-53e5e2115bd2"
POLICY       = "B2C_1A_FirePhoneSignUpOrSignInWithPhoneOrEmail"
REDIRECT_URI = f"msal{CLIENT_ID}://auth"
SCOPE        = f"https://{TENANT}.onmicrosoft.com/Mobile/read offline_access openid"
API_BASE     = "https://mobileapi.gdhv-iot.com"

API_HEADERS = {
    "app_name":              "FlameConnect",
    "api_version":           "1.0",
    "app_version":           "2.22.0",
    "app_device_os":         "android",
    "device_version":        "14",
    "device_manufacturer":   "Python",
    "device_model":          "FlameConnectReader",
    "lang_code":             "en",
    "country":               "US",
    "logging_required_flag": "True",
    "Content-Type":          "application/json",
}

PARAM_POWER      = 321
PARAM_BRIGHTNESS = 322

CONF_EMAIL        = "email"
CONF_PASSWORD     = "password"
CONF_FIRE_ID      = "fire_id"
CONF_SCAN_INTERVAL = "scan_interval"

DEFAULT_SCAN_INTERVAL = 30  # seconds
