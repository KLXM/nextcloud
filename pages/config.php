<?php
namespace Klxm\Nextcloud;

$content = '';

if (\rex_post('config-submit', 'boolean')) {
    $this->setConfig(\rex_post('config', [
        ['baseurl', 'string'],
        ['username', 'string'],
        ['password', 'string'],
        ['rootfolder', 'string']
    ]));
    
    echo \rex_view::success(\rex_i18n::msg('nextcloud_config_saved'));
}

$content .= '<div class="rex-form">';
$content .= '<form action="' . \rex_url::currentBackendPage() . '" method="post">';

$formElements = [];

// Basis-URL
$n = [];
$n['label'] = '<label for="nextcloud-baseurl">' . \rex_i18n::msg('nextcloud_baseurl') . '</label>';
$n['field'] = '<input type="url" id="nextcloud-baseurl" name="config[baseurl]" value="' . $this->getConfig('baseurl') . '" class="form-control"/>';
$formElements[] = $n;

// Benutzername
$n = [];
$n['label'] = '<label for="nextcloud-username">' . \rex_i18n::msg('nextcloud_username') . '</label>';
$n['field'] = '<input type="text" id="nextcloud-username" name="config[username]" value="' . $this->getConfig('username') . '" class="form-control"/>';
$formElements[] = $n;

// App-Passwort
$n = [];
$n['label'] = '<label for="nextcloud-password">' . \rex_i18n::msg('nextcloud_password') . '</label>';
$n['field'] = '<input type="password" id="nextcloud-password" name="config[password]" value="' . $this->getConfig('password') . '" class="form-control"/>';
$n['notice'] = \rex_i18n::msg('nextcloud_password_notice');
$formElements[] = $n;

// Root-Ordner
$n = [];
$n['label'] = '<label for="nextcloud-rootfolder">' . \rex_i18n::msg('nextcloud_rootfolder') . '</label>';
$n['field'] = '<input type="text" id="nextcloud-rootfolder" name="config[rootfolder]" value="' . $this->getConfig('rootfolder') . '" class="form-control" placeholder="/"/>';
$n['notice'] = \rex_i18n::msg('nextcloud_rootfolder_notice');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/form.php');

// Submit
$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save rex-form-aligned" type="submit" name="config-submit" value="1">' . \rex_i18n::msg('save') . '</button>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/submit.php');

$content .= '</form>';
$content .= '</div>';

// Ausgabe Fragment
$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit');
$fragment->setVar('title', \rex_i18n::msg('nextcloud_configuration'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
