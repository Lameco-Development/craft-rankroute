<?php

namespace lameco\rankroute\services;

use craft\base\Component;

/**
 * Picks the field handler for one field by priority (specialised handlers at 50, the
 * default handler at -100). Handlers are registered in Phase 2 (issue #2).
 */
class FieldHandlerRegistry extends Component
{
}
