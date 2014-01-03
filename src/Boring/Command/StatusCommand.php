<?php
namespace Boring\Command;

use Boring\Application;
use Boring\GitRepo;
use Boring\Util\ArrayUtil;
use Boring\Util\Filesystem;
use Boring\Util\Process as ProcessUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class StatusCommand extends BaseCommand {

  const DISPLAY_ALL_THRESHOLD = 10;

  /**
   * @var Filesystem
   */
  var $fs;

  /**
   * @param string|null $name
   * @param array $parameters list of configuration parameters to accept ($key => $label)
   */
  public function __construct($name = NULL) {
    $this->fs = new Filesystem();
    parent::__construct($name);
  }

  protected function configure() {
    $this
      ->setName('status')
      ->setDescription('Show the status of any nested git repositories')
      ->setHelp("Show the status of any nested git repositories.\n\nNote: This will fetch upstream repositories to help determine the status (unless you specify --offline mode).")
      ->addArgument('path', InputArgument::IS_ARRAY, 'The local base path to search', array(getcwd()))
      ->addOption('status', NULL, InputOption::VALUE_REQUIRED, 'Filter table output by repo statuses ("all","novel","boring","auto")', 'auto')
      ->addOption('offline', 'O', InputOption::VALUE_NONE, 'Offline mode: Do not fetch latest data about remote repositories');
    //->addOption('scan', 's', InputOption::VALUE_NONE, 'Force an immediate scan for new git repositories before doing anything')
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    $input->setArgument('path', $this->fs->toAbsolutePaths($input->getArgument('path')));
    $this->fs->validateExists($input->getArgument('path'));
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $output->writeln("<info>[[ Finding repositories ]]</info>");
    $scanner = new \Boring\GitRepoScanner();
    $gitRepos = $scanner->scan($input->getArgument('path'));

    if ($input->getOption('status') == 'auto') {
      $input->setOption('status', count($gitRepos) > self::DISPLAY_ALL_THRESHOLD ? 'novel' : 'all');
    }

    $output->writeln("<info>[[ Checking statuses ]]</info>");
    /** @var \Symfony\Component\Console\Helper\ProgressHelper $progress */
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($output, 1 + count($gitRepos));
    $progress->advance();
    $rows = array();
    $hiddenCount = 0;
    foreach ($gitRepos as $gitRepo) {
      /** @var \Boring\GitRepo $gitRepo */
      if (!$input->getOption('offline') && $gitRepo->getUpstreamBranch() !== NULL) {
        ProcessUtil::runOk($gitRepo->command('git fetch'));
      }
      if ($gitRepo->matchesStatus($input->getOption('status'))) {
        $rows[] = array(
          $gitRepo->getStatusCode(),
          $this->fs->formatPrettyPath($gitRepo->getPath(), $input->getArgument('path')),
          $gitRepo->getLocalBranch(),
          $gitRepo->getUpstreamBranch(),
        );
      }
      else {
        $hiddenCount++;
      }
      $progress->advance();
    }
    $progress->finish();

    $output->writeln("<info>[[ Results ]]</info>\n");
    if (!empty($rows)) {
      $table = $this->getApplication()->getHelperSet()->get('table');
      $table
        ->setHeaders(array('Status', 'Path', 'Local Branch', 'Remote Branch'))
        ->setRows($rows);
      $table->render($output);

      $chars = $this->getUniqueChars(ArrayUtil::collect($rows, 0));
      foreach ($chars as $char) {
        switch ($char) {
          case ' ':
            break;
          case 'M':
            $output->writeln("[M] Local repo has (m)odifications that have not been committed");
            break;
          case 'N':
            $output->writeln("[N] Local repo has (n)ew files that have not been committed");
            break;
          case 'P':
            $output->writeln("[P] Local commits have not been (p)ushed");
            break;
          case 'B':
            $output->writeln("[B] Local and remote (b)ranch names are suspiciously different");
            break;
          case 'S':
            $output->writeln("[S] Changes have been (s)tashed");
            break;
          default:
            throw new \RuntimeException("Unrecognized status code [$char]");
        }
      }
    }
    else {
      $output->writeln("No repositories to display.");
    }

    if ($hiddenCount > 0) {
      switch ($input->getOption('status')) {
        case 'novel':
          $output->writeln("NOTE: Omitted information about $hiddenCount boring repo(s). To display all, use --status=all.");
          break;
        case 'boring':
          $output->writeln("NOTE: Omitted information about $hiddenCount novel repo(s). To display all, use --status=all.");
          break;
        default:
          $output->writeln("NOTE: Omitted information about $hiddenCount repo(s). To display all, use --status=all.");
      }
    }
  }

  function getUniqueChars($items) {
    $chars = array();
    foreach ($items as $item) {
      foreach (str_split($item) as $char) {
        $chars{$char} = 1;
      }
    }
    ksort($chars);
    return array_keys($chars);
  }
}