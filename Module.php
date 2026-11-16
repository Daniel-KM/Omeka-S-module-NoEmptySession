<?php declare(strict_types=1);

namespace NoEmptySession;

use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\Application;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

/**
 * Create the session and its cookie only when there is something to store.
 *
 * @copyright Daniel Berthereau, 2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function init(ModuleManager $moduleManager): void
    {
        // The listener should run before the bootstrap of session and the
        // bootstrap of authentication.
        $moduleManager->getEventManager()->getSharedManager()->attach(
            Application::class,
            MvcEvent::EVENT_BOOTSTRAP,
            [new Mvc\SessionListener(), 'onBootstrap'],
            10000
        );
    }
}
