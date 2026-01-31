<?php

declare(strict_types=1);

namespace phpbb\atproto;

class ext extends \phpbb\extension\base
{
    /**
     * Check if extension is enableable.
     * Requires sodium extension for token encryption.
     */
    public function is_enableable()
    {
        return extension_loaded('sodium') && PHP_VERSION_ID >= 80400;
    }

    /**
     * Enable step - run migrations.
     */
    public function enable_step($old_state)
    {
        return parent::enable_step($old_state);
    }

    /**
     * Disable step.
     */
    public function disable_step($old_state)
    {
        return parent::disable_step($old_state);
    }

    /**
     * Purge step - clean up data.
     */
    public function purge_step($old_state)
    {
        return parent::purge_step($old_state);
    }
}
