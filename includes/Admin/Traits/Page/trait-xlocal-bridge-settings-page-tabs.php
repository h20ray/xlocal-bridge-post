<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/trait-xlocal-bridge-settings-page-tabs-overview-receiver.php';
require_once __DIR__ . '/trait-xlocal-bridge-settings-page-tabs-sender-advanced.php';
require_once __DIR__ . '/trait-xlocal-bridge-settings-page-tabs-debug-logs.php';
require_once __DIR__ . '/trait-xlocal-bridge-settings-page-tabs-bulk-docs.php';

trait Xlocal_Bridge_Settings_Page_Tabs_Trait {
    use Xlocal_Bridge_Settings_Page_Tabs_Overview_Receiver_Trait;
    use Xlocal_Bridge_Settings_Page_Tabs_Sender_Advanced_Trait;
    use Xlocal_Bridge_Settings_Page_Tabs_Debug_Logs_Trait;
    use Xlocal_Bridge_Settings_Page_Tabs_Bulk_Docs_Trait;
}
