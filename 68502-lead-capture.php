<?php
/**
 * Plugin Name: Applied Leads Inbox
 * Description: Theme-friendly Applied Leads Inbox form capture 
 * Version: 0.2.0
 * Author: Jerry Smith
 */

if (!defined('ABSPATH')) exit;

define('WS68502_VERSION', '0.2.0');
define('WS68502_LEAD_ENDPOINT', 'https://skynet.semcat.net/v2/message');

class WS68502_Lead_Capture {
  const OPTION_KEY = 'ws68502_lead_capture_settings';
  const NONCE_ACTION = 'ws68502_submit_lead';

  private function us_states() {
  return [
    'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California',
    'CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia',
    'HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa',
    'KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland',
    'MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri',
    'MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey',
    'NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio',
    'OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina',
    'SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont',
    'VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming'
  ];
}

  public function __construct() {
    // Admin settings
    add_action('admin_menu', [$this, 'register_settings_page']);
    add_action('admin_init', [$this, 'register_settings']);

    // Shortcode
    add_shortcode('applied_leads_inbox', [$this, 'render_shortcode']);

    // Assets (only when shortcode exists)
    add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);

    // AJAX handlers (public + logged-in)
    add_action('wp_ajax_ws68502_submit_lead', [$this, 'handle_ajax_submit']);
    add_action('wp_ajax_nopriv_ws68502_submit_lead', [$this, 'handle_ajax_submit']);
  }

  /** -------------------------
   *  Settings
   *  ------------------------- */
  public function register_settings_page() {
    add_options_page(
  'Applied Leads Inbox',
  'Applied Leads Inbox',
  'manage_options',
  'applied-leads-inbox',
  [$this, 'settings_page_html']
);
  }

  public function register_settings() {
    register_setting('ws68502_lead_capture_group', self::OPTION_KEY, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => [
        'tenant_id' => '',
        'api_key' => '',
        'source_name'  => 'Website',
        'state_mode'    => 'all',      // 'all' or 'selected'
        'states_allowed'=> [],         // array of state abbreviations
        
      ],
    ]);
  }

public function sanitize_settings($input) {
  $out = [];

  // Existing fields
  $out['tenant_id'] = isset($input['tenant_id'])
    ? sanitize_text_field($input['tenant_id'])
    : '';

  $out['api_key'] = isset($input['api_key'])
    ? sanitize_text_field($input['api_key'])
    : '';

  $out['source_name'] = isset($input['source_name'])
    ? substr(trim(sanitize_text_field($input['source_name'])), 0, 20)
    : 'Website';

  if (empty($out['source_name'])) {
    $out['source_name'] = 'Website';
  }

  /* ----------------------------------------
   * NEW: State Mode (all vs selected)
   * ---------------------------------------- */

  $mode = isset($input['state_mode'])
    ? sanitize_text_field($input['state_mode'])
    : 'all';

  $out['state_mode'] = in_array($mode, ['all','selected'], true)
    ? $mode
    : 'all';

  /* ----------------------------------------
   * NEW: Allowed States Array
   * ---------------------------------------- */

  $allowed = isset($input['states_allowed'])
    ? (array) $input['states_allowed']
    : [];

  $states = array_keys($this->us_states());

  $allowed_clean = [];

  foreach ($allowed as $abbr) {
    $abbr = sanitize_text_field($abbr);
    if (in_array($abbr, $states, true)) {
      $allowed_clean[] = $abbr;
    }
  }

  $out['states_allowed'] = array_values(array_unique($allowed_clean));

  return $out;
}


public function settings_page_html() {
  if (!current_user_can('manage_options')) return;

$settings = wp_parse_args(get_option(self::OPTION_KEY, []), [
  'tenant_id'    => '',
  'api_key'      => '',
  'source_name'  => 'Website',
  'state_mode'      => 'all',
  'states_allowed'  => [],
]);

  $tenant_id   = esc_attr($settings['tenant_id']);
$api_key     = esc_attr($settings['api_key']);
$source_name = esc_attr($settings['source_name']);
  ?>
  <div class="wrap">
    <h1>Applied Leads Inbox Settings</h1>

    <form method="post" action="options.php">
      <?php
        settings_fields('ws68502_lead_capture_group');
        do_settings_sections('ws68502_lead_capture_group');
      ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="ws68502_tenant_id">Tenant ID</label></th>
          <td>
            <input
              type="text"
              id="ws68502_tenant_id"
              name="<?php echo self::OPTION_KEY; ?>[tenant_id]"
              value="<?php echo $tenant_id; ?>"
              class="regular-text"
              autocomplete="off"
            />
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="ws68502_api_key">API Key</label></th>
          <td>
            <input
              type="password"
              id="ws68502_api_key"
              name="<?php echo self::OPTION_KEY; ?>[api_key]"
              value="<?php echo $api_key; ?>"
              class="regular-text"
              autocomplete="new-password"
            />
            <p class="description">Saved in the WordPress database (wp_options). Not stored in your code.</p>
          </td>
        </tr>
                <tr>
  <th scope="row"><label for="ws68502_source_name">Source Name</label></th>
  <td>
    <input
      type="text"
      id="ws68502_source_name"
      name="<?php echo self::OPTION_KEY; ?>[source_name]"
      value="<?php echo esc_attr($source_name); ?>"
      class="regular-text"
      maxlength="20"
    />
    <p class="description">
      Max 20 characters. Defaults to <strong>Website</strong> if left blank.
    </p>
  </td>

</tr>

<?php

  $all_states = $this->us_states();
$state_mode = $settings['state_mode'];
$states_allowed = is_array($settings['states_allowed']) ? $settings['states_allowed'] : [];
?>

<tr>
  <th scope="row">State Dropdown</th>
  <td>
    <fieldset>
      <label style="display:block; margin-bottom:6px;">
        <input type="radio"
               name="<?php echo self::OPTION_KEY; ?>[state_mode]"
               value="all"
               <?php checked($state_mode, 'all'); ?> />
        Show all states (This will show all 50 states in the dropdown for State)
      </label>

      <label style="display:block; margin-bottom:10px;">
        <input type="radio"
               name="<?php echo self::OPTION_KEY; ?>[state_mode]"
               value="selected"
               <?php checked($state_mode, 'selected'); ?> />
        Only show selected states (This will show only the states you select in the dropdown for states)
      </label>

      <div id="ws68502_state_picker"
           style="border:1px solid #dcdcde; background:#fff; padding:10px; border-radius:6px; max-width:760px;">
        <div style="display:flex; gap:8px; margin-bottom:10px; align-items:center; flex-wrap:wrap;">
          <button type="button" class="button" id="ws68502_select_all_states">Select all</button>
          <button type="button" class="button" id="ws68502_clear_all_states">Clear</button>
          <span class="description">Pick the states you want available in the public form.</span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(2, minmax(240px, 1fr)); gap:6px 18px;">
          <?php foreach ($all_states as $abbr => $name): ?>
            <label style="display:flex; gap:8px; align-items:center;">
              <input type="checkbox"
                     name="<?php echo self::OPTION_KEY; ?>[states_allowed][]"
                     value="<?php echo esc_attr($abbr); ?>"
                     <?php checked(in_array($abbr, $states_allowed, true)); ?> />
              <span><?php echo esc_html($name . " ($abbr)"); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <p class="description" style="margin-top:10px;">
        Tip: If “Only show selected states” is chosen and none are selected, the dropdown will fall back to showing all states.
      </p>
    </fieldset>

<script>
  (function(){
    const picker = document.getElementById('ws68502_state_picker');
    const selectAllBtn = document.getElementById('ws68502_select_all_states');
    const clearBtn = document.getElementById('ws68502_clear_all_states');

    const modeAll = document.querySelector('input[name="<?php echo self::OPTION_KEY; ?>[state_mode]"][value="all"]');
    const modeSelected = document.querySelector('input[name="<?php echo self::OPTION_KEY; ?>[state_mode]"][value="selected"]');

    if (!picker) return;

    function setAll(checked) {
      picker.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = checked);
    }

    function setPickerEnabled(enabled) {
      // Always visible, but "greyed out" when disabled
      picker.style.opacity = enabled ? '1' : '0.45';
      picker.style.filter = enabled ? 'none' : 'grayscale(1)';

      // Disable/enable inputs inside picker
      picker.querySelectorAll('input, button').forEach(el => {
        el.disabled = !enabled;
      });
    }

    function syncUI() {
      const enabled = modeSelected && modeSelected.checked;
      setPickerEnabled(!!enabled);
    }

    if (selectAllBtn) selectAllBtn.addEventListener('click', () => setAll(true));
    if (clearBtn) clearBtn.addEventListener('click', () => setAll(false));

    if (modeAll) modeAll.addEventListener('change', syncUI);
    if (modeSelected) modeSelected.addEventListener('change', syncUI);

    syncUI();
  })();
</script>
  </td>

</tr>
      </table>

      <?php submit_button('Save Settings'); ?>
    </form>

    <hr />
    <h2>Shortcode</h2>
    <p>Use this shortcode on any page:</p>
    <code>[applied_leads_inbox]</code>

    <h2 style="margin-top:16px;">Endpoint</h2>
    <p><code><?php echo esc_html(WS68502_LEAD_ENDPOINT); ?></code></p>
  </div>
  <?php
}


  /** -------------------------
   *  Shortcode + Assets
   *  ------------------------- */
  public function render_shortcode($atts = []) {
    // Mark that we need assets on this request
    $GLOBALS['ws68502_has_shortcode'] = true;

    $nonce = wp_create_nonce(self::NONCE_ACTION);

    ob_start(); ?>
      <div class="ws68502-form-wrap">
  <div class="ws68502-header">
    <h2 class="ws68502-title">Get a Quote</h2>
    <p class="ws68502-subtitle">
      Fill out this form and someone will contact you shortly.
    </p>
  </div>
        <form class="ws68502-form" id="ws68502LeadForm" novalidate>
          <div class="ws68502-grid">
            <div class="ws68502-field">
              <label>First Name *</label>
              <input type="text" name="firstName" required />
              <div class="ws68502-error" data-for="firstName"></div>
            </div>

            <div class="ws68502-field">
              <label>Last Name *</label>
              <input type="text" name="lastName" required />
              <div class="ws68502-error" data-for="lastName"></div>
            </div>

            <div class="ws68502-field ws68502-col-2">
              <label>Address Line 1 *</label>
              <input type="text" name="address1" required />
              <div class="ws68502-error" data-for="address1"></div>
            </div>

            <div class="ws68502-field ws68502-col-2">
              <label>Address Line 2</label>
              <input type="text" name="address2" />
              <div class="ws68502-error" data-for="address2"></div>
            </div>

            <div class="ws68502-field">
              <label>City *</label>
              <input type="text" name="city" required />
              <div class="ws68502-error" data-for="city"></div>
            </div>

<div class="ws68502-field">
  <label>State *</label>
  <select name="state" required>
    <option value="">Select State</option>
    <?php
$all_states = $this->us_states();

$settings = wp_parse_args(get_option(self::OPTION_KEY, []), [
  'state_mode'     => 'all',
  'states_allowed' => [],
]);

$states_allowed = is_array($settings['states_allowed']) ? $settings['states_allowed'] : [];
$state_mode     = $settings['state_mode'];

$states_to_show = $all_states;

if ($state_mode === 'selected' && !empty($states_allowed)) {
  $states_to_show = array_intersect_key($all_states, array_flip($states_allowed));
}

foreach ($states_to_show as $abbr => $name) {
  echo '<option value="' . esc_attr($abbr) . '">' . esc_html($name) . ' (' . esc_html($abbr) . ')</option>';
}
    ?>
  </select>
  <div class="ws68502-error" data-for="state"></div>
</div>


            <div class="ws68502-field">
              <label>Zip Code *</label>
              <input type="text" name="zip" inputmode="numeric" required />
              <div class="ws68502-error" data-for="zip"></div>
            </div>

            <div class="ws68502-field ws68502-col-2">
            <label>Phone *</label>
            <input type="tel" name="phone" inputmode="tel" autocomplete="tel" required />
            <div class="ws68502-error" data-for="phone"></div>
            </div>


            <div class="ws68502-field ws68502-col-2">
              <label>Email *</label>
              <input type="email" name="email" required />
              <div class="ws68502-error" data-for="email"></div>
            </div>

            <div class="ws68502-field">
              <label>Birthdate *</label>
              <input type="date" name="birthdate" required />
              <div class="ws68502-error" data-for="birthdate"></div>
            </div>
          </div>

          <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>" />

          <button type="submit" class="ws68502-submit button">Submit</button>

          <div class="ws68502-status" aria-live="polite"></div>
        </form>
      </div>
    <?php
    return ob_get_clean();
  }

  public function conditionally_enqueue_assets() {
    // If shortcode has already rendered, enqueue.
    // Also check post content for shortcode (covers cases where enqueue runs before render).
    $enqueue = !empty($GLOBALS['ws68502_has_shortcode']);

    if (!$enqueue && is_singular()) {
      global $post;
      if ($post && isset($post->post_content) && has_shortcode($post->post_content, 'applied_leads_inbox')) {
        $enqueue = true;
      }
    }

    if (!$enqueue) return;

    $plugin_url = plugin_dir_url(__FILE__);
    $ver = WS68502_VERSION;

    wp_enqueue_style('ws68502-lead-form', $plugin_url . 'assets/form.css', [], $ver);
    wp_enqueue_script('ws68502-lead-form', $plugin_url . 'assets/form.js', [], $ver, true);

    wp_localize_script('ws68502-lead-form', 'WS68502LeadForm', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'action'  => 'ws68502_submit_lead',
    ]);
  }

  /** -------------------------
   *  AJAX Handler
   *  ------------------------- */

  public function handle_ajax_submit() {
  // Basic response helper
  $fail = function($message, $code = 400, $errors = []) {
    wp_send_json([
      'ok' => false,
      'message' => $message,
      'errors' => $errors,
    ], $code);
    wp_die();
  };

  // Load settings from WP DB (wp_options)
$settings = wp_parse_args(get_option(self::OPTION_KEY, []), [
  'tenant_id'    => '',
  'api_key'      => '',
  'source_name'  => 'Website',
]);

$tenant_id   = $settings['tenant_id'];
$api_key     = $settings['api_key'];
$source_name = $settings['source_name'];

  if (!$tenant_id || !$api_key) {
    $fail('Plugin is not configured (Tenant ID or API Key missing).', 500);
  }

  // Security
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
    $fail('Security check failed. Please refresh and try again.', 403);
  }

  // Gather fields
$data = [
  'firstName' => sanitize_text_field($_POST['firstName'] ?? ''),
  'lastName'  => sanitize_text_field($_POST['lastName'] ?? ''),
  'address1'  => sanitize_text_field($_POST['address1'] ?? ''),
  'address2'  => sanitize_text_field($_POST['address2'] ?? ''),
  'city'      => sanitize_text_field($_POST['city'] ?? ''),
  'state'     => sanitize_text_field($_POST['state'] ?? ''),
  'zip'       => sanitize_text_field($_POST['zip'] ?? ''),
  'email'     => sanitize_email($_POST['email'] ?? ''),
  'phone'     => sanitize_text_field($_POST['phone'] ?? ''),
  'birthdate' => sanitize_text_field($_POST['birthdate'] ?? ''),
];

$all_states = array_keys($this->us_states());
$valid_states = $all_states;

if (($settings['state_mode'] ?? 'all') === 'selected') {
  $picked = isset($settings['states_allowed']) && is_array($settings['states_allowed'])
    ? $settings['states_allowed']
    : [];

  if (!empty($picked)) {
    $valid_states = array_values(array_intersect($all_states, $picked));
  }
}


  // Server-side validation
  $errors = [];


  foreach (['firstName','lastName','address1','city','state','zip','email','birthdate','phone'] as $k) {

    if (empty($data[$k])) $errors[$k] = 'Required';
  }

  $all_states = array_keys($this->us_states());
  $valid_states = $all_states;

if (($settings['state_mode'] ?? 'all') === 'selected') {
  $picked = isset($settings['states_allowed']) && is_array($settings['states_allowed'])
    ? $settings['states_allowed']
    : [];

  if (!empty($picked)) {
    $valid_states = array_values(array_intersect($all_states, $picked));
  }
}

if (!empty($data['state']) && !in_array($data['state'], $valid_states, true)) {
  $errors['state'] = 'Invalid state selection';
}

  if (!empty($data['email']) && !is_email($data['email'])) $errors['email'] = 'Invalid email';
  if (!empty($data['state']) && strlen($data['state']) !== 2) $errors['state'] = 'Use 2-letter state code';
  if (!empty($data['zip']) && !preg_match('/^\d{5}(-\d{4})?$/', $data['zip'])) $errors['zip'] = 'Invalid ZIP';
  $phone_digits = preg_replace('/\D+/', '', $data['phone']);
  if (strlen($phone_digits) !== 10) {
  $errors['phone'] = 'Enter a 10-digit phone number';
}


  if (!empty($errors)) {
    $fail('Please fix the highlighted fields.', 422, $errors);
  }

  // Build Skynet payload
  $today = current_time('timestamp'); // WP timezone-aware
  $click_through_date = date('m-d-Y', $today); // MM-DD-YYYY

  $payload = [
    'tenant' => $tenant_id,
    'first_name' => $data['firstName'],
    'middle_initial' => '',
    'last_name' => $data['lastName'],
    'postcode' => $data['zip'],
    'phone_numbers' => [[
  'number' => $data['phone'],
  'type'   => 'mobile',
]], // add later if you add a phone field
    'email' => $data['email'],
    'principality' => $data['state'],
    'origin' => 'Website',
    'rank' => 1,
    'click_through_date' => $click_through_date,
    'business_type' => 'Personal',
    'business_name' => '',
    'country_code' => 'US',
    'lob' => 'HOME',
    'payment_type' => '',
    'lead_format' => 'json',
    'data' => [
      'Version' => '8.0',
      'Proposer' => [
        'Business' => false,
        'Forename' => $data['firstName'],
        'Surname' => $data['lastName'],
        'DateOfBirth' => $data['birthdate'], // YYYY-MM-DD
        'Phones' => [[
  'PhoneType'       => 'Home',
  'CountryCode'     => '1',
  'Number'          => $phone_digits,
  'CallPermission'  => 'Obtained',
  'isPrimary'       => 'true', // they want string, not boolean
]],
        'FaxNumber' => '',
        'EmailAddress' => $data['email'],
        'Address' => [
          'AddressLine1' => $data['address1'],
          'AddressLine2' => $data['address2'],
          'City' => $data['city'],
          'StateProvince' => $data['state'],
          'Country' => 'USA',
          'Postcode' => $data['zip'],
        ],
        'Marketing' => [
          'Phone' => true,
          'Email' => true,
          'SMS' => false,
          'Fax' => false,
          'Post' => false,
          'ThirdParty' => false,
        ],
        'BusinessName' => null,
      ],
      'AdditionalPeople' => [],
      'PolicyLine' => [
        'ApplicationType' => '999',
        'ApplicationTypeVersion' => '',
        'PolicyLineType' => 'HOME',
        'TypeOfBusiness' => 'Personal',
      ],

          "Policy"=> [
        "EffectiveDate"=> "2026-02-05T00:00:00+00:00",
        "ExpirationDate"=> "2027-02-05T00:00:00+00:00",
        "IssuingLocation"=> "DE",
        "Paid"=> false
          ],

       "Import"=> [
        "EnteredDate"=> "2026-01-18T00:00:00+00:00",
        "Source"=> [
            "Name"=> $source_name,
        ],
        "State"=> "Detailed",
        "IssuingLocale"=> "US"
      ],   


      'Quote' => [
        'Description' => 'Website Lead',
        'Risk' => wp_json_encode(new stdClass()),
        'Structure' => new stdClass(),
      ],
      'Rating' => [
        'Rates' => [],
      ],
    ],
  ];


$response = wp_remote_post(WS68502_LEAD_ENDPOINT, [
  'timeout' => 12,
  'headers' => [
    'Content-Type' => 'application/json',
    'X-API-KEY'    => $api_key,
    'User-Agent'   => '68502-Lead-Capture/' . WS68502_VERSION . ' (' . home_url() . ')',
  ],
  'body' => wp_json_encode($payload),
]);



  if (is_wp_error($response)) {
    $fail('Could not reach lead service. ' . $response->get_error_message(), 502);
  }

  $status = wp_remote_retrieve_response_code($response);
  $body   = wp_remote_retrieve_body($response);

  if ($status < 200 || $status >= 300) {
    $fail('Lead service returned an error.', 502, [
      'status' => $status,
      'body' => $body,
    ]);
  }

  wp_send_json([
    'ok' => true,
    'message' => 'Thanks! Your info was submitted successfully.',
  ]);
  wp_die();
}




}



new WS68502_Lead_Capture();


