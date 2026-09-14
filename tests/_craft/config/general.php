<?php

/**
 * General config for the integration-test app. Admin changes stay allowed so tests can seed
 * sections and fields. Project config stays in the test database alone — the harness boots
 * with CRAFT_EPHEMERAL=1, which turns YAML writing off. The system is pinned live because
 * the API controllers allow anonymous access on live sites only, and the ephemeral install
 * never gets the `system.live` project-config entry a normal install writes.
 */
return [
    'allowAdminChanges' => true,
    'devMode' => true,
    'isSystemLive' => true,
];
