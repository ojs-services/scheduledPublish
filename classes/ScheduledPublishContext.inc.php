<?php

/**
 * @file classes/ScheduledPublishContext.inc.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishContext
 * @brief Gives a scheduled task the journal context that a page request would have.
 *
 * Scheduled tasks run without a journal in the router (CLI) or with the journal of
 * whatever page triggered the task runner. Publication hooks of other plugins read
 * $request->getContext(); without the right journal they crash or use the wrong settings.
 */

class ScheduledPublishContext
{
    /**
     * Put the journal into the router and load its plugins. Call leave() afterwards.
     * @param $journal Journal
     * @return array State for leave()
     */
    public static function enter($journal)
    {
        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        if (!$router) {
            import('classes.core.PageRouter');
            $router = new PageRouter();
            $router->setApplication(Application::get());
            $request->setRouter($router);
        }
        // Without a dispatcher (CLI) the notification emails cannot build their links.
        $dispatcher = Application::get()->getDispatcher();
        if (!$request->getDispatcher()) {
            $request->setDispatcher($dispatcher);
        }
        if (empty($router->_dispatcher)) { // getDispatcher() asserts when it is unset
            $router->setDispatcher($dispatcher);
        }
        // On the command line OJS has no session, yet publishing asks the session manager
        // (Validation::isLoggedInAs). The core manager would then insert a session with an
        // empty id and fail on the second article; give it a session that touches no table.
        if (defined('SESSION_DISABLE_INIT') && is_null(Registry::get('sessionManager', true, null))) {
            Registry::set('sessionManager', new ScheduledPublishCliSessionManager());
        }
        $saved = [
            'router' => $router,
            'had' => is_array($router->_contexts) && array_key_exists(1, $router->_contexts),
            'prev' => isset($router->_contexts[1]) ? $router->_contexts[1] : null,
        ];
        $router->_contexts[1] = $journal;
        self::loadJournalPlugins($journal->getId());
        return $saved;
    }

    /** @param $saved array From enter() */
    public static function leave($saved)
    {
        $router = $saved['router'];
        if ($saved['had']) {
            $router->_contexts[1] = $saved['prev'];
        } else {
            unset($router->_contexts[1]);
        }
    }

    /**
     * Load the journal's enabled generic plugins that are not loaded yet. A plugin that is
     * already loaded is left alone: loading it again would register its hooks a second time.
     * @param $journalId int
     */
    public static function loadJournalPlugins($journalId)
    {
        $loadedClasses = [];
        foreach ((array) PluginRegistry::getPlugins('generic') as $plugin) {
            $loadedClasses[strtolower(get_class($plugin))] = true;
            $loadedClasses[strtolower($plugin->getName())] = true;
        }
        $products = Application::get()->getEnabledProducts('plugins.generic', (int) $journalId);
        foreach ((array) $products as $product) {
            $className = (string) $product->getProductClassName();
            if ($className === '' || isset($loadedClasses[strtolower($className)])) {
                continue;
            }
            PluginRegistry::loadPlugin('generic', $product->getProduct(), (int) $journalId);
            $loadedClasses[strtolower($className)] = true;
        }
    }
}

import('lib.pkp.classes.session.SessionManager');
import('lib.pkp.classes.session.Session');

/**
 * A session manager for command-line runs: holds an empty, unsaved session so that core
 * code which consults the session (Validation::isLoggedInAs) works without writing to the
 * sessions table. Used only when OJS itself has disabled session initialisation.
 */
class ScheduledPublishCliSessionManager extends SessionManager
{
    /** @var Session */
    private $_cliSession;

    public function __construct()
    {
        // Deliberately not calling the parent: it starts a PHP session and inserts a row.
        $this->_cliSession = new Session();
        $this->_cliSession->setSessionData('');
    }

    /** @copydoc SessionManager::getUserSession() */
    public function getUserSession()
    {
        return $this->_cliSession;
    }

    /** @copydoc SessionManager::updateSessionLifetime() */
    public function updateSessionLifetime($expireTime = 0)
    {
        return true;
    }
}
