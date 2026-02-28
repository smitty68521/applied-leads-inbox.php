<?php
/**
 * Plugin Name: Applied Leads Inbox
 * Description: Theme-friendly Applied Leads Inbox form capture 
 * Version: 0.3.0
 * Author: Jerry Smith
 */

if (!defined('ABSPATH')) exit;

define('WS68502_VERSION', '0.3.0');
define('WS68502_LEAD_ENDPOINT', 'https://skynet.semcat.net/v2/message');

class WS68502_Lead_Capture {
  const OPTION_KEY = 'ws68502_lead_capture_settings';  //Legacy. This is before I added the multiple forms capability. 
  const NONCE_ACTION = 'ws68502_submit_lead';
  const OPTION_GLOBAL = 'ali_global_settings'; //This is for the support of multiple forms
  const OPTION_FORMS  = 'ali_forms'; //This is also for the support of multiple forms
  const OPTION_MIGRATED = 'ali_migrated_v1';

  private function admin_base_url($extra = []) {
  $url = admin_url('options-general.php?page=applied-leads-inbox');
  if (!empty($extra)) {
    $url = add_query_arg($extra, $url);
  }
  return $url;
}

private function ensure_forms_array($forms) {
  return is_array($forms) ? $forms : [];
}

private function make_new_form_id($forms) {
  // Create a unique-ish ID: form_abc123
  $i = 0;
  do {
    $suffix = strtolower(wp_generate_password(6, false, false));
    $id = 'form_' . $suffix;
    $i++;
  } while (isset($forms[$id]) && $i < 20);

  if (isset($forms[$id])) {
    $id = 'form_' . time();
  }
  return sanitize_key($id);
}

public function handle_admin_add_form() {
  if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }
  check_admin_referer('ali_add_form');

  $forms = $this->ensure_forms_array(get_option(self::OPTION_FORMS, []));
  $new_id = $this->make_new_form_id($forms);

  $forms[$new_id] = [
    'id'             => $new_id,
    'label'          => 'New Form',
    'source_name'    => 'Website',
    'lob'            => 'HOME',
    'state_mode'     => 'all',
    'states_allowed' => [],
    'preset'         => 'standard',
  ];

  update_option(self::OPTION_FORMS, $forms, false);

  // Redirect straight into Details for that new form
  wp_safe_redirect($this->admin_base_url(['form' => $new_id, 'created' => 1]));
  exit;
}

public function handle_admin_delete_form() {
  if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }
  check_admin_referer('ali_delete_form');

  $form_id = sanitize_key($_POST['form_id'] ?? '');
  if ($form_id === '') {
    wp_safe_redirect($this->admin_base_url(['deleted' => 0]));
    exit;
  }

  // Protect default from deletion (optional but recommended)
  if ($form_id === 'default') {
    wp_safe_redirect($this->admin_base_url(['deleted' => 0, 'reason' => 'default']));
    exit;
  }

  $forms = $this->ensure_forms_array(get_option(self::OPTION_FORMS, []));
  if (isset($forms[$form_id])) {
    unset($forms[$form_id]);
    update_option(self::OPTION_FORMS, $forms, false);
  }

  wp_safe_redirect($this->admin_base_url(['deleted' => 1]));
  exit;
}

public function handle_admin_save_form_details() {
  if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }
  check_admin_referer('ali_save_form_details');

  $form_id = sanitize_key($_POST['form_id'] ?? '');
  if ($form_id === '') {
    wp_safe_redirect($this->admin_base_url(['saved' => 0]));
    exit;
  }

  $forms = $this->ensure_forms_array(get_option(self::OPTION_FORMS, []));
  if (!isset($forms[$form_id]) || !is_array($forms[$form_id])) {
    wp_safe_redirect($this->admin_base_url(['saved' => 0]));
    exit;
  }

  $label = substr(trim(sanitize_text_field($_POST['label'] ?? 'Website Form')), 0, 60);
  if ($label === '') $label = 'Website Form';

  $source_name = substr(trim(sanitize_text_field($_POST['source_name'] ?? 'Website')), 0, 20);
  if ($source_name === '') $source_name = 'Website';

  $lob = strtoupper(sanitize_text_field($_POST['lob'] ?? 'HOME'));
  $lob = in_array($lob, ['HOME','AUTO','OTHER'], true) ? $lob : 'HOME';

  $mode = sanitize_text_field($_POST['state_mode'] ?? 'all');
  $mode = in_array($mode, ['all','selected'], true) ? $mode : 'all';

  $valid_states = array_keys($this->us_states());
  $picked = isset($_POST['states_allowed']) ? (array) $_POST['states_allowed'] : [];
  $picked_clean = [];

  foreach ($picked as $abbr) {
    $abbr = sanitize_text_field($abbr);
    if (in_array($abbr, $valid_states, true)) $picked_clean[] = $abbr;
  }

  $forms[$form_id] = wp_parse_args($forms[$form_id], [
    'id'             => $form_id,
    'label'          => 'Website Form',
    'source_name'    => 'Website',
    'lob'            => 'HOME',
    'state_mode'     => 'all',
    'states_allowed' => [],
    'preset'         => 'standard',
  ]);

  $forms[$form_id]['label']          = $label;
  $forms[$form_id]['source_name']    = $source_name;
  $forms[$form_id]['lob']            = $lob;
  $forms[$form_id]['state_mode']     = $mode;
  $forms[$form_id]['states_allowed'] = array_values(array_unique($picked_clean));

  update_option(self::OPTION_FORMS, $forms, false);

  wp_safe_redirect($this->admin_base_url(['form' => $form_id, 'saved' => 1]));
  exit;
}

private function get_global_settings() {
  return wp_parse_args(get_option(self::OPTION_GLOBAL, []), [
    'tenant_id' => '',
    'api_key'   => '',
  ]);
}

private function get_forms() {
  $forms = get_option(self::OPTION_FORMS, []);
  return is_array($forms) ? $forms : [];
}



private function get_form($form_id = 'default') {
  $forms = $this->get_forms();
  if (!is_array($forms) || empty($forms)) {
    return null;
  }

  // Normalize ID
  $form_id = is_string($form_id) && $form_id !== '' ? $form_id : 'default';

  // Pick a form: requested -> default -> first
  if (isset($forms[$form_id]) && is_array($forms[$form_id])) {
    $form = $forms[$form_id];
  } elseif (isset($forms['default']) && is_array($forms['default'])) {
    $form_id = 'default';
    $form = $forms['default'];
  } else {
    $form = reset($forms);
    if (!is_array($form)) return null;
    $form_id = isset($form['id']) ? sanitize_key($form['id']) : 'default';
  }

  // Normalize expected keys
  return wp_parse_args($form, [
    'id'             => $form_id,
    'label'          => 'Website Form',
    'source_name'    => 'Website',
    'lob'            => 'HOME',
    'state_mode'     => 'all',
    'states_allowed' => [],
    'preset'         => 'standard',
  ]);
}

private function maybe_migrate_legacy_settings() {
  
  

  // If we've already migrated, do nothing.
  if (get_option(self::OPTION_MIGRATED)) return;

  // If the new forms structure already exists, don't touch anything.
  $forms = $this->get_forms();
  if (!empty($forms)) return;

  // Pull legacy settings (your existing single-form plugin settings)
  $legacy = wp_parse_args(get_option(self::OPTION_KEY, []), [
    'tenant_id'       => '',
    'api_key'         => '',
    'source_name'     => 'Website',
    'state_mode'      => 'all',
    'states_allowed'  => [],
    'preset'         => 'standard',
  ]);

  // Normalize legacy data
  $legacy['tenant_id']   = sanitize_text_field($legacy['tenant_id']);
  $legacy['api_key']     = sanitize_text_field($legacy['api_key']);
  $legacy['source_name'] = substr(trim(sanitize_text_field($legacy['source_name'])), 0, 20);

  if ($legacy['source_name'] === '') {
    $legacy['source_name'] = 'Website';
  }

  if (!in_array($legacy['state_mode'], ['all', 'selected'], true)) {
    $legacy['state_mode'] = 'all';
  }

  if (!is_array($legacy['states_allowed'])) {
    $legacy['states_allowed'] = [];
  }

  // Keep only valid state abbreviations
  $valid_states = array_keys($this->us_states());
  $legacy_states = array_values(array_unique(array_intersect($valid_states, $legacy['states_allowed'])));

  // Create global settings (only if legacy had something; avoids overwriting empty globals)
  $existing_global = get_option(self::OPTION_GLOBAL, []);
  if (empty($existing_global) || !is_array($existing_global)) {
    $existing_global = [];
  }

  $new_global = wp_parse_args($existing_global, [
    'tenant_id' => $legacy['tenant_id'],
    'api_key'   => $legacy['api_key'],
  ]);

  update_option(self::OPTION_GLOBAL, $new_global, false);

  // Create the default form profile (future-proof fields included)
  $forms = [
    'default' => [
      'id'             => 'default',
      'label'          => 'Website Form',
      'source_name'    => $legacy['source_name'],
      'lob'            => 'HOME',
      'state_mode'     => $legacy['state_mode'],
      'states_allowed' => $legacy_states,
      'preset'         => 'standard',
    ],
  ];

  update_option(self::OPTION_FORMS, $forms, false);
  // Mark migration complete
  update_option(self::OPTION_MIGRATED, 1, false);
}

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

   // $this->maybe_migrate_legacy_settings();
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


    //Form Handlers
    add_action('admin_post_ali_add_form', [$this, 'handle_admin_add_form']);
    add_action('admin_post_ali_delete_form', [$this, 'handle_admin_delete_form']);
    add_action('admin_post_ali_save_form_details', [$this, 'handle_admin_save_form_details']);
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

    //Legacy..Before multiple forms were added to this. This is left for backward compatibility.  
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

    // NEW: global settings option to add multiple forms capability
  register_setting('ws68502_lead_capture_group', self::OPTION_GLOBAL, [
    'type' => 'array',
    'sanitize_callback' => function($input){
      return [
        'tenant_id' => sanitize_text_field($input['tenant_id'] ?? ''),
        'api_key'   => sanitize_text_field($input['api_key'] ?? ''),
      ];
    },
    'default' => [
      'tenant_id' => '',
      'api_key'   => '',
    ],
  ]);

   // NEW: forms option (for now just default profile fields you’re editing)
  register_setting('ws68502_lead_capture_group', self::OPTION_FORMS, [
    'type' => 'array',
    'sanitize_callback' => function($input){
      $out = is_array($input) ? $input : [];
      $d = is_array($out['default'] ?? null) ? $out['default'] : [];

      $source_name = substr(trim(sanitize_text_field($d['source_name'] ?? 'Website')), 0, 20);
      if ($source_name === '') $source_name = 'Website';

      $mode = sanitize_text_field($d['state_mode'] ?? 'all');
      $mode = in_array($mode, ['all','selected'], true) ? $mode : 'all';

      $states = array_keys($this->us_states());
      $allowed = isset($d['states_allowed']) ? (array)$d['states_allowed'] : [];
      $allowed_clean = [];

      foreach ($allowed as $abbr) {
        $abbr = sanitize_text_field($abbr);
        if (in_array($abbr, $states, true)) $allowed_clean[] = $abbr;
      }

      $out['default'] = wp_parse_args($d, [
        'id'             => 'default',
        'label'          => 'Website Form',
        'lob'            => 'HOME',
        'preset'         => 'standard',
      ]);

      $out['default']['source_name']    = $source_name;
      $out['default']['state_mode']     = $mode;
      $out['default']['states_allowed'] = array_values(array_unique($allowed_clean));
      $out['default']['id'] = 'default';

      return $out;
    },
    'default' => [],
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

  $global = $this->get_global_settings();
  $tenant_id = esc_attr($global['tenant_id'] ?? '');
  $api_key   = esc_attr($global['api_key'] ?? '');

  $forms = $this->get_forms();
  $forms = is_array($forms) ? $forms : [];

  // If they click Details, we render an editor view for that form
  $active_form_id = sanitize_key($_GET['form'] ?? '');
  $active_form = $active_form_id ? $this->get_form($active_form_id) : null;

  ?>
  <div class="wrap">
    <h1>Applied Leads Inbox</h1>

    <!-- SECTION 1: Global Settings -->
    <h2>Connection Settings</h2>
    <p class="description">These apply to all forms (Tenant + API Key).</p>

    <form method="post" action="options.php">
      <?php
        settings_fields('ws68502_lead_capture_group');
        // You registered OPTION_GLOBAL under this group, so options.php can save it.
      ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="ali_tenant_id">Tenant ID</label></th>
          <td>
            <input
              type="text"
              id="ali_tenant_id"
              name="<?php echo self::OPTION_GLOBAL; ?>[tenant_id]"
              value="<?php echo $tenant_id; ?>"
              class="regular-text"
              autocomplete="off"
            />
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="ali_api_key">API Key</label></th>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
  <input
    type="password"
    id="ali_api_key"
    name="<?php echo self::OPTION_GLOBAL; ?>[api_key]"
    value="<?php echo $api_key; ?>"
    class="regular-text"
    autocomplete="new-password"
  />

    <button type="button"
          class="button"
          id="ali_toggle_api_key"
          aria-label="Show API Key">
    👁 Show
  </button>

  <script>
  (function(){
    const input = document.getElementById('ali_api_key');
    const btn   = document.getElementById('ali_toggle_api_key');

    if (!input || !btn) return;

    btn.addEventListener('click', function(){
      const isHidden = input.type === 'password';

      input.type = isHidden ? 'text' : 'password';
      btn.textContent = isHidden ? '🙈 Hide' : '👁 Show';
    });
  })();
</script>


</div>

            <p class="description">Saved in the WordPress database (wp_options). Not stored in your code.</p>
          </td>
        </tr>
      </table>

      <?php submit_button('Save Connection Settings'); ?>
    </form>

    <hr />

    <!-- SECTION 2: Forms List -->
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <h2 style="margin:0;">Forms</h2>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
        <?php wp_nonce_field('ali_add_form'); ?>
        <input type="hidden" name="action" value="ali_add_form" />
        <button type="submit" class="button button-primary">+ Add New Form</button>
      </form>
    </div>

    <p class="description">
  Tip: The <code>default</code> form can be used as <code>[applied_leads_inbox]</code> (no attributes needed).
</p>

    <table class="widefat striped" style="margin-top:10px;">
      <thead>
        <tr>
          <th>Form Name</th>
          <th>Shortcode</th>
          <th style="width:140px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($forms)): ?>
          <tr>
            <td colspan="3">
              <em>No forms yet.</em> Click <strong>+ Add New Form</strong> to create one.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($forms as $id => $f): 
            if (!is_array($f)) continue;
            $id = sanitize_key($f['id'] ?? $id);
            if ($id === '') continue;

            $label = esc_html($f['label'] ?? $id);
            $shortcode = ($id === 'default')
            ? '[applied_leads_inbox]'
            : '[applied_leads_inbox form="' . $id . '"]';
            $detail_url = esc_url($this->admin_base_url(['form' => $id]));
          ?>
            <tr>
              <td>
                <strong><?php echo $label; ?></strong>
                <div class="description">ID: <code><?php echo esc_html($id); ?></code></div>
              </td>
              <td>
                <code><?php echo esc_html($shortcode); ?></code>
              </td>
              <td>
                <a class="button" href="<?php echo $detail_url; ?>">Details</a>

                <?php if ($id !== 'default'): ?>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <?php wp_nonce_field('ali_delete_form'); ?>
                    <input type="hidden" name="action" value="ali_delete_form" />
                    <input type="hidden" name="form_id" value="<?php echo esc_attr($id); ?>" />
                    <button
                      type="submit"
                      class="button button-link-delete"
                      onclick="return confirm('Delete this form? This cannot be undone.');"
                    >Delete</button>
                  </form>
                <?php else: ?>
                  <span class="description" style="margin-left:8px;">(default)</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ($active_form): ?>
      <hr />
      <h2>Form Details: <?php echo esc_html($active_form['label'] ?? $active_form_id); ?></h2>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ali_save_form_details'); ?>
        <input type="hidden" name="action" value="ali_save_form_details" />
        <input type="hidden" name="form_id" value="<?php echo esc_attr($active_form['id']); ?>" />

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ali_label">Form Name</label></th>
            <td>
              <input type="text" id="ali_label" name="label"
                     value="<?php echo esc_attr($active_form['label'] ?? 'Website Form'); ?>"
                     class="regular-text" maxlength="60" />
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ali_source">Source Name</label></th>
            <td>
              <input type="text" id="ali_source" name="source_name"
                     value="<?php echo esc_attr($active_form['source_name'] ?? 'Website'); ?>"
                     class="regular-text" maxlength="20" />
              <p class="description">Max 20 characters. This is what you send in Import.Source.Name.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="ali_lob">Line of Business</label></th>
            <td>
              <?php $lob = strtoupper($active_form['lob'] ?? 'HOME'); ?>
              <select id="ali_lob" name="lob">
                <option value="HOME" <?php selected($lob, 'HOME'); ?>>HOME</option>
                <option value="AUTO" <?php selected($lob, 'AUTO'); ?>>AUTO</option>
                <option value="OTHER" <?php selected($lob, 'OTHER'); ?>>OTHER</option>
              </select>
            </td>
          </tr>

          <?php
            $all_states = $this->us_states();
            $state_mode = $active_form['state_mode'] ?? 'all';
            $states_allowed = is_array($active_form['states_allowed'] ?? null) ? $active_form['states_allowed'] : [];
          ?>
          <tr>
            <th scope="row">State Dropdown</th>
            <td>
              <label style="display:block; margin-bottom:6px;">
                <input type="radio" name="state_mode" value="all" <?php checked($state_mode, 'all'); ?> />
                Show all states
              </label>

              <label style="display:block; margin-bottom:10px;">
                <input type="radio" name="state_mode" value="selected" <?php checked($state_mode, 'selected'); ?> />
                Only show selected states
              </label>

              <div style="border:1px solid #dcdcde; background:#fff; padding:10px; border-radius:6px; max-width:760px;">
                <div style="display:grid; grid-template-columns:repeat(2, minmax(240px, 1fr)); gap:6px 18px;">
                  <?php foreach ($all_states as $abbr => $name): ?>
                    <label style="display:flex; gap:8px; align-items:center;">
                      <input type="checkbox" name="states_allowed[]"
                             value="<?php echo esc_attr($abbr); ?>"
                             <?php checked(in_array($abbr, $states_allowed, true)); ?> />
                      <span><?php echo esc_html($name . " ($abbr)"); ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <p class="description" style="margin-top:10px;">
                If “Only show selected states” is chosen and none are selected, your frontend code can fall back to all states.
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row">Shortcode</th>
            <td>
              <?php
                 $details_shortcode = ($active_form['id'] === 'default')
                  ? '[applied_leads_inbox]'
                   : '[applied_leads_inbox form="' . $active_form['id'] . '"]';
?>
<code><?php echo esc_html($details_shortcode); ?></code>
            </td>
          </tr>
        </table>

        <?php submit_button('Save Form Details'); ?>
        <a class="button" href="<?php echo esc_url($this->admin_base_url()); ?>">Back to Forms</a>
      </form>
    <?php endif; ?>

    <hr />
    <h2>Endpoint</h2>
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

    $atts = shortcode_atts([
    'form' => 'default',
  ], $atts, 'applied_leads_inbox');

  $form_id = sanitize_key($atts['form']);
  if ($form_id === '') $form_id = 'default';

  $form = $this->get_form($form_id);
  
  if (!$form) {
    return '<div class="ws68502-form-wrap"><p><strong>Applied Leads Inbox:</strong> No forms configured.</p></div>';
  }

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



$state_mode     = $form['state_mode'] ?? 'all';
$states_allowed = is_array($form['states_allowed'] ?? null) ? $form['states_allowed'] : [];
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
          <input type="hidden" name="form_id" value="<?php echo esc_attr($form['id']); ?>" />

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
$global = $this->get_global_settings();

$form_id = sanitize_key($_POST['form_id'] ?? 'default');
if ($form_id === '') $form_id = 'default';

$form = $this->get_form($form_id) ?: $this->get_form('default');
if (!$form) {
  $form = [
    'id'            => 'default',
    'source_name'    => 'Website',
    'state_mode'     => 'all',
    'states_allowed' => [],
    'lob'            => 'HOME',
  ];
}

$tenant_id   = esc_attr($global['tenant_id'] ?? '');
$api_key     = esc_attr($global['api_key'] ?? '');

$source_name    = esc_attr($form['source_name'] ?? 'Website');

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




  // Server-side validation
// Server-side validation
// Server-side validation
$errors = [];

foreach (['firstName','lastName','address1','city','state','zip','email','birthdate','phone'] as $k) {
  if (empty($data[$k])) $errors[$k] = 'Required';
}

$all_states   = array_keys($this->us_states());
$valid_states = $all_states;

if (($form['state_mode'] ?? 'all') === 'selected') {
  $picked = isset($form['states_allowed']) && is_array($form['states_allowed'])
    ? $form['states_allowed']
    : [];

  if (!empty($picked)) {
    $valid_states = array_values(array_intersect($all_states, $picked));
  }
}

if (!empty($data['state']) && empty($errors['state']) && strlen($data['state']) !== 2) {
  $errors['state'] = 'Use 2-letter state code';
} elseif (!empty($data['state']) && empty($errors['state']) && !in_array($data['state'], $valid_states, true)) {
  $errors['state'] = 'Invalid state selection';
}

if (!empty($data['email']) && !is_email($data['email'])) {
  $errors['email'] = 'Invalid email';
}

if (!empty($data['zip']) && !preg_match('/^\d{5}(-\d{4})?$/', $data['zip'])) {
  $errors['zip'] = 'Invalid ZIP';
}

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
            "Name"=> ["Name"=> $source_name],
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


