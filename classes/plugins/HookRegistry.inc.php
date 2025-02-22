<?php

/**
 * @file classes/plugins/HookRegistry.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HookRegistry
 * @ingroup plugins
 *
 * @brief Class for linking core functionality with plugins
 */

namespace PKP\plugins;

use PKP\core\Registry;

define('HOOK_SEQUENCE_CORE', 0x000);
define('HOOK_SEQUENCE_NORMAL', 0x100);
define('HOOK_SEQUENCE_LATE', 0x200);
define('HOOK_SEQUENCE_LAST', 0x300);

class HookRegistry
{
    /**
     * Get the current set of hook registrations.
     *
     * @param string $hookName Name of hook to optionally return
     *
     * @return mixed Array of all hooks or just those attached to $hookName, or
     *   null if nothing has been attached to $hookName
     */
    public static function &getHooks($hookName = null)
    {
        $hooks = & Registry::get('hooks', true, []);

        if ($hookName) {
            if (isset($hooks[$hookName])) {
                $hook = & $hooks[$hookName];
            } else {
                $hook = null;
            }
            return $hook;
        }

        return $hooks;
    }

    /**
     * Set the hooks table for the given hook name to the supplied array
     * of callbacks.
     *
     * @param string $hookName Name of hook to set
     * @param array $callbacks Array of callbacks for this hook
     */
    public static function setHooks($hookName, $callbacks)
    {
        $hooks = & HookRegistry::getHooks();
        $hooks[$hookName] = & $callbacks;
    }

    /**
     * Clear hooks registered against the given name.
     *
     * @param string $hookName Name of hook
     */
    public static function clear($hookName)
    {
        $hooks = & HookRegistry::getHooks();
        unset($hooks[$hookName]);
        return $hooks;
    }

    /**
     * Register a hook against the given hook name.
     *
     * @param string $hookName Name of hook to register against
     * @param object $callback Callback pseudotype
     * @param int $hookSequence Optional hook sequence specifier HOOK_SEQUENCE_...
     */
    public static function register($hookName, $callback, $hookSequence = HOOK_SEQUENCE_NORMAL)
    {
        $hooks = & HookRegistry::getHooks();
        $hooks[$hookName][$hookSequence][] = & $callback;
    }

    /**
     * Call each callback registered against $hookName in sequence.
     * The first callback that returns a value that evaluates to true
     * will interrupt processing and this function will return its return
     * value; otherwise, all callbacks will be called in sequence and the
     * return value of this call will be the value returned by the last
     * callback.
     *
     * @param string $hookName The name of the hook to register against
     * @param string $args Hooks are called with this as the second param
     */
    public static function call($hookName, $args = null)
    {
        // Called only by Unit Test
        // The implementation is a bit quirky as this has to work when
        // executed statically.
        if (self::rememberCalledHooks(true)) {
            // Remember the called hooks for testing.
            $calledHooks = & HookRegistry::getCalledHooks();
            $calledHooks[] = [
                $hookName, $args
            ];
        }

        $hooks = & HookRegistry::getHooks();
        if (!isset($hooks[$hookName])) {
            return false;
        }

        if (isset($hooks[$hookName])) {
            ksort($hooks[$hookName], SORT_NUMERIC);
            foreach ($hooks[$hookName] as $priority => $hookList) {
                foreach ($hookList as $hook) {
                    if ($result = call_user_func($hook, $hookName, $args)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }


    //
    // Methods required for testing only.
    //
    /**
     * Set/query the flag that triggers storing of
     * called hooks.
     *
     * @param bool $askOnly When set to true, the flag will not
     *   be changed but only returned.
     * @param bool $updateTo When $askOnly is set to 'true' then
     *   this parameter defines the value of the flag.
     *
     * @return bool The current value of the flag.
     */
    public static function rememberCalledHooks($askOnly = false, $updateTo = true)
    {
        static $rememberCalledHooks = false;
        if (!$askOnly) {
            $rememberCalledHooks = $updateTo;
        }
        return $rememberCalledHooks;
    }

    /**
     * Switch off the function to store hooks and delete all stored hooks.
     * Always call this after using otherwise we get a severe memory.
     *
     * @param bool $leaveAlive Set this to true if you only want to
     *   delete hooks stored so far but if you want to record future
     *   hook calls, too.
     */
    public static function resetCalledHooks($leaveAlive = false)
    {
        if (!$leaveAlive) {
            HookRegistry::rememberCalledHooks(false, false);
        }
        $calledHooks = & HookRegistry::getCalledHooks();
        $calledHooks = [];
    }

    /**
     * Return a reference to the stored hooks.
     *
     * @return array
     */
    public static function &getCalledHooks()
    {
        static $calledHooks = [];
        return $calledHooks;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\plugins\HookRegistry', '\HookRegistry');
}
