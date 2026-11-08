<?php

/**
 * @file classes/ScheduledPublishContext.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishContext
 * @brief Gives a scheduled task the journal context that a page request would have.
 *
 * Scheduled tasks run without a journal in the router (CLI) or with the journal of whatever
 * page triggered the task runner. Publication hooks and listeners of other plugins read
 * $request->getContext(); without the right journal they crash or use the wrong settings.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\core\Application;
use APP\core\PageRouter;
use PKP\context\Context;
use PKP\plugins\PluginRegistry;
use Throwable;

class ScheduledPublishContext
{
    /**
     * Put the journal into the router and load its plugins. Call leave() afterwards.
     * @return array State for leave()
     */
    public static function enter(Context $journal): array
    {
        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        if (!$router) {
            $router = new PageRouter();
            $router->setApplication(Application::get());
            $request->setRouter($router);
        }

        // Without a dispatcher (CLI) notification emails cannot build their links. The
        // getters throw or assert when the dispatcher is unset, hence the guards.
        $dispatcher = Application::get()->getDispatcher();
        try {
            $hasRequestDispatcher = (bool) $request->getDispatcher();
        } catch (Throwable $e) {
            $hasRequestDispatcher = false;
        }
        if (!$hasRequestDispatcher) {
            $request->setDispatcher($dispatcher);
        }
        try {
            $hasRouterDispatcher = (bool) $router->getDispatcher();
        } catch (Throwable $e) {
            $hasRouterDispatcher = false;
        }
        if (!$hasRouterDispatcher) {
            $router->setDispatcher($dispatcher);
        }

        $saved = ['router' => $router, 'prev' => $router->_context];
        $router->_context = $journal;
        self::loadJournalPlugins((int) $journal->getId());
        return $saved;
    }

    /** @param $saved array From enter() */
    public static function leave(array $saved): void
    {
        $saved['router']->_context = $saved['prev'];
    }

    /**
     * Load the journal's enabled generic plugins that are not loaded yet. A plugin that is
     * already loaded is left alone: loading it again would register its hooks a second time.
     */
    public static function loadJournalPlugins(int $journalId): void
    {
        $loadedClasses = [];
        foreach ((array) PluginRegistry::getPlugins('generic') as $plugin) {
            $loadedClasses[strtolower(get_class($plugin))] = true;
            $loadedClasses[strtolower((new \ReflectionClass($plugin))->getShortName())] = true;
            $loadedClasses[strtolower($plugin->getName())] = true;
        }
        $products = Application::get()->getEnabledProducts('plugins.generic', $journalId);
        foreach ((array) $products as $product) {
            $className = (string) $product->getProductClassName();
            if ($className === '' || isset($loadedClasses[strtolower($className)])) {
                continue;
            }
            PluginRegistry::loadPlugin('generic', $product->getProduct(), $journalId);
            $loadedClasses[strtolower($className)] = true;
        }
    }
}
