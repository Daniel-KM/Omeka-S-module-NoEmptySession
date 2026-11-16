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
    /**
     * Above this number of rows, the previous table is dropped in background.
     */
    const LARGE_TABLE_ROWS = 100000;

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

    public function getConfigForm(PhpRenderer $renderer)
    {
        $stats = $this->sessionTableStats();
        $html = '<p>' . $renderer->escapeHtml(sprintf(
            $renderer->translate('The table of sessions contains about %1$s rows and uses %2$s MB, of which %3$s MB are free space.'), // @translate
            number_format($stats['rows'], 0, ',', ' '),
            number_format($stats['size'] / 1048576, 1, ',', ' '),
            number_format($stats['free'] / 1048576, 1, ',', ' ')
        )) . '</p>';

        // The form is not built by the form element manager, so no csrf is
        // added: the module config form of the core has its own.
        $form = new Form\ConfigForm();
        $form->init();
        return $html . $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        if (empty($controller->params()->fromPost('noemptysession_truncate'))) {
            return true;
        }

        $messenger = $controller->messenger();
        try {
            $background = $this->emptySessionTable();
        } catch (\Throwable $e) {
            $messenger->addError(new Message(
                'The table of sessions cannot be emptied: %s', // @translate
                $e->getMessage()
            ));
            return false;
        }

        $messenger->addSuccess(
            $background
                ? 'The table of sessions was emptied. The previous one is being dropped in background.' // @translate
                : 'The table of sessions was emptied.' // @translate
        );
        return true;
    }

    /**
     * Empty the table of sessions and return true when the previous table is
     * dropped in background.
     *
     * A small table is truncated. A big table is replaced by an empty copy in a
     * single atomic rename, so the requests are not blocked, then the previous
     * table is dropped in background, because it may be long on a slow disk.
     * The session of the current user is saved at the end of the request, so it
     * remains logged in.
     */
    protected function emptySessionTable(): bool
    {
        $services = $this->getServiceLocator();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        if ($this->sessionTableStats()['rows'] < static::LARGE_TABLE_ROWS) {
            $connection->executeStatement('TRUNCATE TABLE `session`');
            $this->addIndexModified('session');
            return false;
        }

        /** @var \Omeka\Stdlib\Cli $cli */
        $cli = $services->get('Omeka\Cli');
        $phpPath = $cli->getCommandPath('php');
        $script = __DIR__ . '/data/scripts/drop-table.php';
        if (!$phpPath || !is_readable($script)) {
            $connection->executeStatement('TRUNCATE TABLE `session`');
            $this->addIndexModified('session');
            return false;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `session_noemptysession_new`');
        $connection->executeStatement('CREATE TABLE `session_noemptysession_new` LIKE `session`');
        $this->addIndexModified('session_noemptysession_new');
        // A previous table may remain when the background drop failed.
        $connection->executeStatement('DROP TABLE IF EXISTS `session_noemptysession_old`');
        $connection->executeStatement('RENAME TABLE `session` TO `session_noemptysession_old`, `session_noemptysession_new` TO `session`');

        $cli->execute(sprintf(
            '%s %s --omeka-path %s > /dev/null 2>&1 &',
            escapeshellcmd($phpPath),
            escapeshellarg($script),
            escapeshellarg(OMEKA_PATH)
        ));
        return true;
    }

    /**
     * Add the index on the column "modified" when missing.
     *
     * The garbage collector of the sessions deletes the old ones by this column,
     * so without index, it scans and locks the whole table. The table is empty
     * here, so the index is added instantly.
     */
    protected function addIndexModified(string $table): void
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $exists = $connection->executeQuery(
            "SHOW INDEX FROM `$table` WHERE `Column_name` = 'modified'"
        )->fetchOne();
        if (!$exists) {
            $connection->executeStatement("ALTER TABLE `$table` ADD INDEX `modified` (`modified`)");
        }
    }

    /**
     * Get the estimated number of rows and the sizes of the table of sessions.
     *
     * The number of rows is estimated, because a count is slow on a big table.
     */
    protected function sessionTableStats(): array
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $stats = $connection->executeQuery(
            'SELECT `TABLE_ROWS`, `DATA_LENGTH` + `INDEX_LENGTH`, `DATA_FREE`
            FROM `information_schema`.`TABLES`
            WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = "session"'
        )->fetchNumeric() ?: [0, 0, 0];
        return [
            'rows' => (int) $stats[0],
            'size' => (int) $stats[1],
            'free' => (int) $stats[2],
        ];
    }
}
