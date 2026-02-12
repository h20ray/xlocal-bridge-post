<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/Page/trait-xlocal-bridge-settings-page-layout.php';
require_once __DIR__ . '/Page/trait-xlocal-bridge-settings-page-tabs.php';
require_once __DIR__ . '/Page/trait-xlocal-bridge-settings-page-fields.php';

trait Xlocal_Bridge_Settings_Page_Trait {
    use Xlocal_Bridge_Settings_Page_Layout_Trait;
    use Xlocal_Bridge_Settings_Page_Tabs_Trait;
    use Xlocal_Bridge_Settings_Page_Fields_Trait;
}
