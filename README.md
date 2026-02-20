# 📬Applied Leads Inbox – WordPress Lead Capture Plugin

A secure, theme-friendly WordPress plugin for capturing website leads and posting them to a configured endpoint using a Tenant ID and API Key.

Built by **Jerry Smith**.

---

## 🚀 Features

- Secure lead capture form
- Admin-configurable Tenant ID + API Key
- Server-side validation
- Shortcode support
- Clean, theme-friendly markup
- Error handling with user feedback
- Secure POST to external endpoint

---

## 📦 Installation

### Option 1 – WordPress Admin Upload

1. Log in to WordPress Admin
2. Navigate to **Plugins → Add New**
3. Click **Upload Plugin**
4. Upload the plugin `.zip` file
5. Click **Activate**

### Option 2 – Manual Installation

1. Extract the plugin folder
2. Upload it to: /wp-content/plugins/
3. Activate via **Plugins → Installed Plugins**


## ⚙️ Configuration

After activation:

1. Navigate to: Settings → Applied Leads Inbox


2. Enter your:
- **Tenant ID**
- **API Key**

You also have the option to name the form.  It defaults to Website.

3. Click **Save Settings**

These credentials are used to authenticate lead submissions.  They are saved in a Wordpress Database. 

---

## 📝 Usage

Add the shortcode below to any page or post:

### Example

1. Go to **Pages → Add New**
2. Add a Shortcode block
3. Insert: [applied_leads_inbox]
4. Publish the page

## 📡 How It Works

When a user submits the form:

1. Input is sanitized
2. Required fields are validated
3. A payload is constructed
4. Data is POSTed to: the endpoint provided in your API documentation. 

If successful, the user sees a confirmation message.  
If validation fails, errors are displayed inline.

---

## 🔐 Security

- WordPress nonces
- Sanitized input
- Server-side validation
- API Key stored securely in WordPress options
- API Key is never exposed in frontend source

---

## 🛠 Validation Rules

| Field | Requirement |
|--------|--------------|
| Email  | Valid email format |
| Phone  | 10 digits |
| State  | 2-letter code |
| ZIP    | 5-digit or 5+4 format |
| Required Fields | Cannot be empty |

---

## 🎨 Styling

The plugin includes basic form styling.

To override styles, add CSS in: Appearance → Customize → Additional CSS

Example:

```css
.ws68502-form input,
.ws68502-form select {
  border-radius: 6px;
}

🧪 Troubleshooting

400 Response

Invalid API Key

Missing required field

Malformed payload

422 Error

Validation failure

Missing required input

502 / Server Error

Endpoint unavailable

Hosting firewall blocking outbound requests

🔍 Debugging

Enable WordPress debug logging:

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

Logs will be written to: /wp-content/debug.log


📈 Version

Current Version: 0.1.0

## Future Enhancements

1. **Configurable State Dropdown**
   - Select which U.S. states appear in the dropdown
   - Restrict submissions to approved states only
   - Manage state availability via plugin settings

2. **CAPTCHA / Human Verification Integration**
   - Native reCAPTCHA support (v2 or v3)
   - Easy API key configuration
   - Optional enable/disable toggle
   - Spam protection

3. **Multiple Named Forms**
   - Create multiple forms within the same WordPress site
   - Assign unique names
   - Generate individual shortcodes

4. **Admin Leads Dashboard**
   - Centralized list of submitted leads
   - Export to CSV
   - Lead status tracking
   - Search and filtering

   ## Licensing & Usage

This plugin integrates with third-party systems that may require
separate authorization, API access, or licensing agreements.

Distribution of this plugin does not grant access to external services.

Users are responsible for ensuring compliance with any third-party
platform terms of service.