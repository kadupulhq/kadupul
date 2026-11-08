<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* include ldap support */
include_once(__DIR__ . '/lib/ldap.php');

/* set default action */
set_default_action();

// Form credentials require a validated POST; server-authenticated Basic login does not.
$auth_method = read_config_option('auth_method');
if ($auth_method != 2) {
    cacti_require_post_actions(array('login'));

    // Reject request shapes before normalization, plugins or typed credentials.
    // The generic input-error renderer logs the request, including passwords.
    foreach (array('login_username', 'login_password') as $login_field) {
        if (!is_string(get_nfilter_request_var($login_field, ''))) {
            http_response_code(400);
            exit;
        }
    }
}

/**
 * get the username from the post variable
 * For all but basic, this means that two post variables must be
 * set.  Additionally, for basic authentication verify the user,
 * and if not valid generate a fatal error.
 */
$username = auth_get_username(); // Get the username from either basic auth or the login form
$version  = get_cacti_version(); // Get the current Kadupul version

/* initialize some variables */
$user          = array();                             // An array that will include all user details
$user_enabled  = true;                                // A variable to let plugins know that the user is enabled
$guest_user    = false;                               // Indicates the Guest account is being used
$realm         = 0;                                   // The compensated realm used for template and user validation
$frv_realm     = auth_resource_id(get_nfilter_request_var('realm', 0)); // The dropdown value for realm
if ($frv_realm === null) {
    http_response_code(400);
    exit;
}
$error         = false;                               // Global variable, will be true if any errors occur
$error_msg     = '';                                  // The errors message in case there was a login error

/* global variables for exception handling */
global $error, $error_msg;

if (get_nfilter_request_var('action') == 'login' || $auth_method == 2) {
    if (($auth_method == 4 && $frv_realm === 0) || ($auth_method == 3 && in_array($frv_realm, array(0, 1), true))) {
        // User picked 'local' from dropdown;
        $auth_method = 1;
    } else {
        $auth_method = read_config_option('auth_method');
    }

    // Compensate as the dropdown for LDAP is off by one
    if ($frv_realm == 2) {
        $realm = 3;
    } elseif ($auth_method == 2) {
        $realm = $auth_method;
    } else {
        $realm = $frv_realm;
    }

    cacti_log("DEBUG: User '" . $username . "' attempting to login with realm " . $frv_realm . ", using method " . $auth_method, false, 'AUTH', POLLER_VERBOSITY_DEBUG);

    /* an existing install keeps the certificate check it ran with */
    cacti_ldap_tls_settle_requirement();

    switch ($auth_method) {
        case '0': // No authentication, should not be reachable
            $error     = true;
            $error_msg = __esc('Kadupul no longer supports No Authentication mode. Please contact your System Administrator.');

            cacti_log("FATAL: No authentication attempted and not supported.", false, 'AUTH');

            auth_display_custom_error_message($error_msg);

            exit;

            break;
        case '1': // Local authentication
            cacti_log("DEBUG: Local User '" . $username . "' to attempt login.", false, 'AUTH', POLLER_VERBOSITY_DEBUG);

            $user = local_auth_login_process($username);

            break;
        case '2': // Basic authentication
            cacti_log("DEBUG: Basic Auth User '" . $username . "' attempting to login.", false, 'AUTH', POLLER_VERBOSITY_DEBUG);

            $user = basic_auth_login_process($username);

            break;
        case '3': // LDAP Authentication
            cacti_log("DEBUG: LDAP User '" . $username . "' to attempt login.", false, 'AUTH', POLLER_VERBOSITY_DEBUG);

            $user = ldap_login_process($username);

            break;
        case '4': // LDAP Domains login
            cacti_log("DEBUG: Domains User '" . $username . "' to attempt login.", false, 'AUTH', POLLER_VERBOSITY_DEBUG);

            $user = domains_login_process($username);

            break;
        default: // Login Realm not determined
            $error     = true;
            $error_msg = __esc('Unable to determine user Login Realm or Domain. Please contact your System Administrator.');

            cacti_log("LOGIN FAILED: User '" . $username . "' Unable to determine Login Realm.  Exiting.", false, 'AUTH');

            auth_display_custom_error_message($error_msg);

            exit;

            break;
    }

    // A domain login creates its account from the domain's own template, so an
    // empty result without an error must not reach the global template or guest.
    if ($auth_method == 4 && !$error && !cacti_sizeof($user)) {
        $error     = true;
        $error_msg = __('Access Denied!  Login Failed.');
    }

    /* Create user from template if available */
    if (!$error && !cacti_sizeof($user) && get_template_account($username) > 0 && $username != '') {
        $user = auth_login_create_user_from_template($username, $realm);
    }

    /* Guest account checking - Not for builtin */
    if (!$error && !cacti_sizeof($user) && get_guest_account() > 0) {
        /* Locate guest user record */
        $user = db_fetch_row_prepared(
            'SELECT *
			FROM user_auth
			WHERE id = ?',
            array(get_guest_account())
        );

        if ($user) {
            cacti_log("LOGIN: Authenticated user '" . $username . "' using guest account '" . $user['username'] . "'", false, 'AUTH');

            if ($username != '' && get_template_account($username) == 0) {
                raise_message('template_disabled', __('User was Authenticated, but the Template Account is disabled.  Using Guest Account'), MESSAGE_LEVEL_WARN);
            }

            $guest_user = true;
        } else {
            /* error */
            $error     = true;
            $error_msg = __('Access Denied!  Guest user id %s does not exist.  Please contact your Administrator.', read_config_option('guest_user'));

            cacti_log("LOGIN FAILED: Unable to locate guest user '" . read_config_option('guest_user') . "'", false, 'AUTH');

            if ($auth_method == 2) {
                auth_display_custom_error_message($error_msg);
                exit;
            }
        }
    }

    /* We have a valid user, do final checks, log their login attempt, and redirect as required */
    if (!$error && cacti_sizeof($user)) {

        $client_addr = get_client_addr();

        if (!$guest_user) {
            cacti_log("LOGIN: User '" . $user['username'] . "' authenticated from IP Address '" . $client_addr . "'", false, 'AUTH');
        } else {
            cacti_log("LOGIN: Guest User '" . $user['username'] . "' in use from IP Address '" . $client_addr . "'", false, 'AUTH');
        }

        db_execute_prepared(
            'INSERT IGNORE INTO user_log
			(username, user_id, result, ip, time)
			VALUES (?, ?, 1, ?, NOW())',
            array($username, $user['id'], $client_addr)
        );

        /* check if the user account is enabled with the exception of guest users */
        $user_enabled = true;
        if (!$guest_user && isset($user['enabled'])) {
            $user_enabled = ($user['enabled'] == 'on' ? true : false);
        }

        /* check if the user is enabled */
        if (!$user_enabled) {
            $error     = true;
            $error_msg = __('Access Denied!  User account disabled.');

            if ($auth_method == 2) {
                auth_display_custom_error_message($error_msg);
                exit;
            }
        }

        if (!$error && !auth_user_has_access($user)) {
            /* error */
            $error     = true;
            $error_msg = __('You do not have access to any area of Kadupul.  Contact your administrator.');

            cacti_log(sprintf("LOGIN FAILED: User %s with id %s does not have access to any area of Kadupul.", $user['username'], $user['id']), false, 'AUTH');

            if ($auth_method == 2) {
                auth_display_custom_error_message($error_msg);
                exit;
            }
        }

        if (!$error) {
            /* avoid session fixation */
            cacti_session_start(true);

            /* enforce post-auth session/cookie transition hardening */
            if (!cacti_auth_transition((int) $user['id'], 'login')) {
                $error     = true;
                $error_msg = __('Access Denied! User account locked.');
                cacti_session_destroy();
                cacti_session_start(true);
            }

            if (!$error) {
                /* set the php session only after the transition admits the account */
                $_SESSION['sess_user_id'] = $user['id'];

                /* Publish remembered credentials only after the session transition succeeds. */
                if ($auth_method != 2 && $user['id'] !== get_guest_account()
                    && isset_request_var('remember_me') && read_config_option('auth_cache_enabled') == 'on') {
                    // Bind the token to the generation this session was bound to,
                    // so a Logout Everywhere committed meanwhile also revokes it.
                    // A missing binding is refused by set_auth_cookie(), not left unversioned.
                    set_auth_cookie($user, $_SESSION['sess_user_credential'] ?? '');
                }
            }

            if ($error) {
                if ($auth_method == 2) {
                    auth_display_custom_error_message($error_msg);
                    exit;
                }

                header('Location: auth_login.php');
                exit;
            }

            /* handle 'force change password' */
            if ($user['must_change_password'] == 'on' && $auth_method == 1 && $user['password_change'] == 'on') {
                $_SESSION['sess_change_password'] = true;
            }

            if (db_table_exists('user_auth_group')) {
                $group_options = db_fetch_cell_prepared(
                    'SELECT MAX(login_opts)
					FROM user_auth_group AS uag
					INNER JOIN user_auth_group_members AS uagm
					ON uag.id=uagm.group_id
					WHERE user_id = ?
					AND login_opts != 4',
                    array($_SESSION['sess_user_id'])
                );

                if (!empty($group_options)) {
                    $user['login_opts'] = $group_options;
                }
            }

            if (user_setting_exists('user_language', $_SESSION['sess_user_id'])) {
                $_SESSION['sess_user_language'] = read_user_setting('user_language');
            }

            cacti_log("DEBUG: User '" . $username . "' about to re-direct to preferred login page", false, 'AUTH', POLLER_VERBOSITY_DEBUG);

            auth_login_redirect($user['login_opts']);
        }
    } else {
        $id = db_fetch_cell_prepared(
            'SELECT id
			FROM user_auth
			WHERE username = ?
			AND realm = ?',
            array($username, $frv_realm)
        );

        switch ($frv_realm) {
            case '0':
            case '1':
                $realm_name = 'Local';
                break;
            case '2':
                $realm_name = 'LDAP';
                break;
            default:
                $realm_name = 'Domains LDAP';
                break;
        }

        /* BAD username/password builtin and LDAP */
        db_execute_prepared(
            'INSERT IGNORE INTO user_log
			(username, user_id, result, ip, time)
			VALUES (?, ?, 0, ?, NOW())',
            array($username, !empty($id) ? $id : 0, get_client_addr())
        );

        cacti_log('LOGIN FAILED: ' . $realm_name . " Login Failed for user '" . $username . "' from IP Address '" . get_client_addr() . "'.", false, 'AUTH');
    }
}

if (api_plugin_hook_function('custom_login', OPER_MODE_NATIVE) == OPER_MODE_RESKIN) {
    return;
}

$selectedTheme = get_selected_theme();

// Resolve the injected presentation service at the native composition boundary.
// Render sections in place so hooks see the same globals and preceding output,
// including when a plugin deliberately stops the response before the footer.
$loginKernel = (static fn(): \Kadupul\Kernel => require __DIR__ . '/config/bootstrap.php')();
$loginKernel->boot();
$loginRenderer = $loginKernel->getContainer()->get(\Kadupul\IdentityAccess\Infrastructure\Symfony\LoginPageRenderer::class);
$loginCharset = html_escape_charset();
print $loginRenderer->begin(new \Twig\Markup((string) html_escape(CACTI_LOCALE), $loginCharset));
html_common_header(api_plugin_hook_function('login_title', __('Login to Kadupul')));
print $loginRenderer->formStart(__('User Login'), (string) get_current_page());
api_plugin_hook_function(
    'login_before',
    array(
        'error'        => $error,
        'error_msg'    => $error_msg,
        'username'     => $username,
        'user_enabled' => $user_enabled,
        'action'       => get_nfilter_request_var('action')
    )
);
print $loginRenderer->credentials(
    __('Enter your Username and Password below'),
    __('Username'),
    __('Password'),
    new \Twig\Markup((string) html_escape($username), $loginCharset),
);
if (read_config_option('auth_method') == '3' || read_config_option('auth_method') == '4') {
    if (read_config_option('auth_method') == '3') {
        $realms = api_plugin_hook_function(
            'login_realms',
            array(
                '1' => array('name' => __('Local'), 'selected' => false),
                '2' => array('name' => __('LDAP'), 'selected' => true)
            )
        );
    } else {
        // Nothing comes back when no domain is enabled.
        $realms = get_auth_realms(true) ?? array();
    }
    if ($frv_realm && array_key_exists($frv_realm, $realms)) {
        foreach ($realms as $key => $realm) {
            $realms[$key]['selected'] = ($frv_realm == $key);
        }
    }
    $loginRealmLabel = __('Realm');
    $loginRealmChoices = array();
    if (cacti_sizeof($realms)) {
        foreach ($realms as $index => $realm) {
            $loginRealmChoices[] = array(
                'value' => (string) $index,
                'name' => new \Twig\Markup((string) html_escape($realm['name']), $loginCharset),
                'selected' => (bool) $realm['selected'],
            );
        }
    }
    print $loginRenderer->realms($loginRealmLabel, $loginRealmChoices);
}
if (read_config_option('auth_cache_enabled') == 'on') {
    print $loginRenderer->remember(__('Keep me signed in'), isset($_COOKIE['cacti_remembers']) || !isempty_request_var('remember_me'));
}
print $loginRenderer->submit(__('Login'));
api_plugin_hook('login_after');
print $loginRenderer->result(
    new \Twig\Markup($error ? (string) html_escape($error_msg) : '', $loginCharset),
    __('Version %s | %s', $version, COPYRIGHT_YEARS_SHORT),
    (string) CactiSecureHeaders::getNonce(),
    empty($username),
);
include_once(__DIR__ . '/include/global_session.php');
print $loginRenderer->end();
