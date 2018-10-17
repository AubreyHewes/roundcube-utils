<?php

namespace App\Command;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\FetchMode;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Copy a database to a ... database
 *
 * i.e. sqlite -> mysql
 *
 * @package App\Command
 */
class CopyDatabaseCommand extends Command
{

  /**
   * We only support these conversions at the moment
   */
  private const SUPPORTED_SCHEMA_TYPES = ['sqlite', 'mysql'];

  /**
   * The GitHub API base url
   */
  private const GITHUB_API_BASE_URL = 'https://api.github.com';

  /**
   * The GitHub API user agent
   */
  private const GITHUB_API_USER_AGENT = 'kingsquare/roundcube-utils (https://github.com/kingsquare/roundcube-utils)';

  /**
   * The GitHub API commits endpoint for roundcube
   */
  private const RC_SCHEMA_INITIAL_COMMITS = '/repos/roundcube/roundcubemail/commits?since=%s&until=%s&path=SQL/%s.initial.sql';

  /**
   * The GitHub API contents endpoint for roundcube initial schemas
   */
  private const RC_SCHEMA_INITIAL_SOURCE = '/repos/roundcube/roundcubemail/contents/SQL/%s.initial.sql?ref=%s';

  /**
   * @var ConsoleLogger
   */
  private $logger;

  /**
   * @inheritdoc
   */
  protected function configure() : void
  {
    $this
      ->setName('db:copy')
      ->addOption(
        'from-uri',
        'i',
        InputOption::VALUE_REQUIRED,
        'The database uri to copy from'
      )
      ->addOption(
        'to-uri',
        'o',
        InputOption::VALUE_REQUIRED,
        'The database uri to copy to'
      );
  }

  /**
   * @inheritdoc
   *
   * @throws DBALException
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $this->logger = new ConsoleLogger($output);

    $fromUri = $this->getRequiredOption($input, 'from-uri');

    $this->guardSchemaTypeSupported(preg_replace('#^(.+?):.*#', '$1', $fromUri));

    $toUri = $this->getRequiredOption($input, 'to-uri');

    $this->guardSchemaTypeSupported(preg_replace('#^(.+?):.*#', '$1', $toUri));

    $fromConnection = $this->getConnection($fromUri);
    $fromConnection->connect();
    if (!$fromConnection->isConnected()) {
      throw new \InvalidArgumentException('Can not connect to from-uri: ' . $fromUri);
    }
    $this->logger->debug('$fromConnection: connected');

    $toConnection = $this->getConnection($toUri);
    $toConnection->connect();
    if (!$toConnection->isConnected()) {
      throw new \InvalidArgumentException('Can not connect to to-uri: ' . $toUri);
    }
    $this->logger->debug('$toConnection: connected');

    $fromSchemaType = str_replace('pdo_', '', $fromConnection->getParams()['driver']);
    /** @noinspection UnknownInspectionInspection */
    /** @noinspection SqlDialectInspection */
    $fromVersion = $fromConnection->executeQuery(
      'SELECT `value` FROM `system` WHERE `name` = "roundcube-version"'
    )->fetchColumn();
    echo 'From db version: ' . $fromSchemaType . ' ' . $fromVersion . PHP_EOL;

    $toSchemaType = str_replace('pdo_', '', $toConnection->getParams()['driver']);
    echo 'To db version: ' . $toSchemaType . ' ' . $fromVersion . PHP_EOL;

    $schemaSql = $this->getInitialSchema($toSchemaType, $fromVersion);

    $toConnection->exec($schemaSql);

    $tables = $this->getTablesToCopy($fromConnection);

    foreach ($tables as $table) {
      if ($table === 'session') {
        echo 'Skipping: ' . $table . PHP_EOL;
        continue;
      }
      $count = $fromConnection->createQueryBuilder()->select('COUNT(*)')->from($table)->execute()->fetchColumn();
      if (empty($count)) {
        echo 'Copying: ' . $table . ' (0/0)' . PHP_EOL;
        continue;
      }
      // creates a new progress bar (50 units)
      $progressBar = new ProgressBar($output, $count);

      ProgressBar::setFormatDefinition('dus', 'Copying: ' . $table . ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
      /** @noinspection PhpParamsInspection */
      $progressBar->setFormat('dus');

      // starts and displays the progress bar
      $progressBar->start();

      $result = $fromConnection->createQueryBuilder()->select('*')->from($table)->execute();

      while($row = $result->fetch()) {
        // advances the progress bar 1 unit
        try {
          foreach ($row as $key => $value) {
            $quotedKey = $toConnection->quoteIdentifier($key);
            if ($quotedKey !== $key) {
                $row[$quotedKey] = $value;
                unset($row[$key]);
            }
          }
          $toConnection->insert($table, $row);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (UniqueConstraintViolationException $ex) {
          // TODO update if already exists; note could break existing to-data
//          $toConnection->update($table, $row);
        }
        $progressBar->advance();
      }
      // ensures that the progress bar is at 100%
      $progressBar->finish();
      echo PHP_EOL;
    }

    echo PHP_EOL . 'Now connect your roundcube instance to '. $toUri . PHP_EOL . PHP_EOL;
  }

  /**
   * Return an `InputInterface` option that value that can not be empty
   *
   * @param InputInterface $input
   * @param string $option
   *
   * @return bool|null|string|string[]
   */
  private function getRequiredOption(InputInterface $input, string $option)
  {
    $value = $input->getOption($option);
    if (empty($value)) {
        throw new InvalidOptionException($option. ' is required');
    }
    $this->logger->debug('$option: ' . $option . ' = ' . $value);
    return $value;
  }

  /**
   * @param string $type
   * @param string $version
   *
   * @return string SQL
   */
  private function getInitialSchema(string $type, string $version) : string
  {
    $since = preg_replace('#^(\d{4})(\d{2})(\d{2}).*#', '$1-$2-$3T00:00:00', $version);
    $until = date('Y-m-d\T00:00:00', strtotime('+1 day', strtotime($since)));

    $this->logger->debug('retrieving initial schema history');
    $commits = json_decode($this->getClient()->get(
      sprintf(self::RC_SCHEMA_INITIAL_COMMITS, $since, $until, $type)
    )->getBody()->__toString());

    $this->logger->debug('found ' . \count($commits) . ' commits');

    $sql = array_reduce($commits, function ($result, $commit) use ($type, $version) {
      $this->logger->debug('trying commit sha: ' . $commit->sha);
      $raw = $this->getClient()->get(
        sprintf(self::RC_SCHEMA_INITIAL_SOURCE, $type, $commit->sha)
      )->getBody()->__toString();

      if (strpos($raw, $version) !== false) {
        $result = $raw;
      }
      return $result;
    }, '');

    if (empty($sql)) {
      throw new \InvalidArgumentException('Could not fund initial schema for version ' . $version);
    }
    return $this->filterSql($sql);
  }

  /**
   * @param Connection $fromConnection
   *
   * @return string[]
   *
   * @throws DBALException
   */
  private function getTablesToCopy(Connection $fromConnection) : array
  {
    $fromSchemaType = str_replace('pdo_', '', $fromConnection->getParams()['driver']);
    $stmt = '';
    if ($fromSchemaType === 'sqlite') {
      $stmt = 'SELECT name FROM sqlite_master WHERE type=\'table\'';
    }
    if ($fromSchemaType === 'mysql') {
      $stmt = 'SHOW TABLES';
    }
    return $fromConnection->executeQuery($stmt)->fetchAll(FetchMode::COLUMN);
  }

  /**
   * @param string $type
   */
  private function guardSchemaTypeSupported(string $type) : void
  {
    if (!\in_array($type, self::SUPPORTED_SCHEMA_TYPES, true)) {
        throw new InvalidOptionException('schema type ' . $type . ' is not supported');
    }
  }

  /**
   * @param array $options
   *
   * @return Client
   */
  private function getClient(array $options = []) :Client
  {
    return new Client(array_merge($options, [
      'base_uri' => self::GITHUB_API_BASE_URL,
      'headers' => [
        'User-Agent' => self::GITHUB_API_USER_AGENT,
        'Accept'     => 'application/vnd.github.VERSION.raw, application/json',
      ],
    ]));
  }

  /**
   * @param string $sql
   *
   * @return string
   */
  private function filterSql(string $sql) : string
  {
    return implode(PHP_EOL, array_filter(explode(PHP_EOL, $sql), function ($line) {
      return !empty($line) && strpos($line, '--') !== 0;
    }));
  }

  /**
   * @param string $uri
   *
   * @return Connection
   *
   * @throws DBALException
   */
  private function getConnection(string $uri) : Connection
  {
    $config = new Configuration();
    return DriverManager::getConnection([
      'url' => $uri
    ], $config);
  }

}
