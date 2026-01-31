<?php

declare(strict_types=1);

/**
 * Stub for phpBB extension base class.
 * Used for unit testing the extension outside of a full phpBB installation.
 */

namespace phpbb\extension;

if (!class_exists('\phpbb\extension\base')) {
    abstract class base
    {
        public function is_enableable()
        {
            return true;
        }

        public function enable_step($old_state)
        {
            return false;
        }

        public function disable_step($old_state)
        {
            return false;
        }

        public function purge_step($old_state)
        {
            return false;
        }
    }
}
