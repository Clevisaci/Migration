<?php
namespace Migration\Tests;

use DateTime;
use dibi;
use DibiConnection;
use DibiRow;
use InvalidArgumentException;
use Migration;
use Mockery;
use Tester;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/mocks/RunnerMock.php';


interface DibiDriverMock extends \IDibiDriver, \IDibiResultDriver, \IDibiReflector
{

}


class RunnerTest extends Tester\TestCase
{

	private $dibi;
	private $driver;
	private $printer;
	private $runner;

	protected function setUp()
	{
		parent::setUp();
		$driver = Mockery::mock('Migration\Tests\DibiDriverMock');
		class_alias(get_class($driver), 'Dibi' . get_class($driver) . 'Driver');
		$dibi = new DibiConnection(array('driver' => get_class($driver), 'lazy' => TRUE));
		Access($dibi)->driver = $driver;
		$printer = Mockery::mock('Migration\IPrinter');

		$driver->shouldReceive('connect')->atMost()->once();
		$driver->shouldReceive('disconnect')->atMost()->once();
		$driver->shouldReceive('escape')->andReturnUsing(function ($value, $type) {
			switch ($type)
			{
				case dibi::TEXT:
					return "'$value'";
				case dibi::IDENTIFIER:
					return '`' . str_replace('`', '``', $value) . '`';
				case dibi::BOOL:
					return $value ? 1 : 0;
				case dibi::DATE:
					return $value instanceof DateTime ? $value->format("'Y-m-d'") : date("'Y-m-d'", $value);
				case dibi::DATETIME:
					return $value instanceof DateTime ? $value->format("'Y-m-d H:i:s'") : date("'Y-m-d H:i:s'", $value);
			}
			throw new InvalidArgumentException('Unsupported type.');
		});

		$driver->shouldReceive('getResource');

		$this->dibi = $dibi;
		$this->driver = $driver;
		$this->printer = $printer;
		$this->runner = Access(new Migration\Runner($this->dibi, $this->printer));
	}

	private function qar($sql, $r = 0)
	{
		$this->driver->shouldReceive('query')->with($sql)->once()->ordered();
		$this->driver->shouldReceive('getAffectedRows')->withNoArgs()->andReturn($r)->once()->ordered();
	}

	private function qr($sql, array $data)
	{
		$this->driver->shouldReceive('query')->with($sql)->andReturn($this->driver)->once()->ordered();
		foreach ($data as $row)
		{
			$this->driver->shouldReceive('fetch')->with(TRUE)->andReturn($row)->once()->ordered();
		}
		$this->driver->shouldReceive('fetch')->with(TRUE)->andReturn()->once()->ordered();
	}

	public function testNotReset()
	{
		$runner = new RunnerMock($this->dibi, $this->printer);
		$runner->sql = new Migration\File(__FILE__, 'sql');

		$this->printer->shouldReceive('printToExecute')->with(array($runner->sql))->once()->ordered();
		$this->printer->shouldReceive('printExecute')->with($runner->sql, 5)->once()->ordered();
		$this->printer->shouldReceive('printDone')->withNoArgs()->once()->ordered();

		$runner->run(__DIR__, FALSE);

		Assert::same(array(
			array('runSetup'),
			array('lock'),
			array('runInitMigrationTable'),
			array('getAllMigrations'),
			array('getAllFiles', __DIR__),
			array('getToExecute', array(), array(__FILE__ => $runner->sql)),
			array('execute', $runner->sql),
		), $runner->log);
	}

	public function testReset()
	{
		$runner = new RunnerMock($this->dibi, $this->printer);
		$runner->sql = new Migration\File(__FILE__, 'sql');

		$this->printer->shouldReceive('printReset')->withNoArgs()->once()->ordered();
		$this->printer->shouldReceive('printToExecute')->with(array($runner->sql))->once()->ordered();
		$this->printer->shouldReceive('printExecute')->with($runner->sql, 5)->once()->ordered();
		$this->printer->shouldReceive('printDone')->withNoArgs()->once()->ordered();

		$runner->run(__DIR__, TRUE);

		Assert::same(array(
			array('runSetup'),
			array('lock'),
			array('runWipe'),
			array('runInitMigrationTable'),
			array('getAllMigrations'),
			array('getAllFiles', __DIR__),
			array('getToExecute', array(), array(__FILE__ => $runner->sql)),
			array('execute', $runner->sql),
		), $runner->log);
	}

	public function testError()
	{
		$runner = new RunnerMock($this->dibi, $this->printer);
		$runner->error = TRUE;

		$test = $this;
		$this->printer->shouldReceive('printError')->with(new Mockery\Matcher\Closure(function ($e) use ($test)
		{
			Assert::type('Migration\Exception', $e);
			Assert::same('foo bar', $e->getMessage());
			return TRUE;
		}))->once()->ordered();

		$runner->run(__DIR__, FALSE);

		Assert::same(array(), $runner->log);
	}

	public function testRunSetup()
	{
		$this->qar('SET NAMES utf8');
		$this->qar('SET foreign_key_checks = 0');
		$this->qar("SET time_zone = 'SYSTEM'");
		$this->qar("SET sql_mode = 'TRADITIONAL'");
		$this->runner->runSetup();
	}

	public function testRunWipe()
	{
		$this->driver->shouldReceive('getReflector')->withNoArgs()->andReturn($this->driver)->once()->ordered();
		$this->driver->shouldReceive('getTables')->withNoArgs()->andReturn(array(
			array('name' => 'foo'),
			array('name' => 'bar'),
			array('name' => 'migrations'),
			array('name' => 'view_table', 'view' => TRUE),
		))->once()->ordered();
		$this->qar('DROP TABLE `foo`');
		$this->qar('DROP TABLE `bar`');
		$this->qar('DROP TABLE `migrations`');
		$this->qar('DROP VIEW `view_table`');
		$this->runner->runWipe();
	}

	public function testRunInitMigrationTable()
	{
		$this->qar("CREATE TABLE IF NOT EXISTS `migrations` (
			`id` bigint NOT NULL AUTO_INCREMENT,
			`file` text NOT NULL,
			`checksum` text NOT NULL,
			`executed` datetime NOT NULL,
			`ready` smallint(1) NOT NULL default 0,
			PRIMARY KEY (`id`)
		) ENGINE='InnoDB'");
		$this->runner->runInitMigrationTable();
	}

	public function testGetAllMigrations()
	{
		$this->driver->shouldReceive('getResultColumns')->andReturn(array());

		$this->qr("SELECT * FROM `migrations`", array(
			array(
				'id' => 1,
				'file' => 'file',
				'checksum' => 'checksum',
				'executed' => '2011-11-11',
				'ready' => 1,
			),
			array(
				'id' => 2,
				'file' => 'file2',
				'checksum' => 'checksum2',
				'executed' => '2011-11-12',
				'ready' => 1,
			),
		));
		$r = $this->runner->getAllMigrations();

		Assert::equal(array(
			'file' => new DibiRow(array(
					'id' => 1,
					'file' => 'file',
					'checksum' => 'checksum',
					'executed' => '2011-11-11',
					'ready' => 1,
				)),
			'file2' => new DibiRow(array(
					'id' => 2,
					'file' => 'file2',
					'checksum' => 'checksum2',
					'executed' => '2011-11-12',
					'ready' => 1,
				)),
		), $r);
	}

	public function testGetAllFiles()
	{
		$tmp = realpath(TEMP_DIR);
		file_put_contents("$tmp/_1.sql", '1');
		file_put_contents("$tmp/_2.sql", '2');
		$a = realpath("$tmp/_1.sql");
		$b = realpath("$tmp/_2.sql");

		$r = $this->runner->getAllFiles($tmp);

		Assert::same(array($a, $b), array_keys($r));

		Assert::same('_1.sql', $r[$a]->file);
		Assert::same('_2.sql', $r[$b]->file);
		Assert::same('c4ca4238a0b923820dcc509a6f75849b', $r[$a]->checksum);
		Assert::same('c81e728d9d4c2f636f067f89cc14862c', $r[$b]->checksum);
		$tmp = new DateTime('now');
		Assert::same($tmp->format('c'), $r[$a]->executed->format('c'));
		$tmp = new DateTime('now');
		Assert::same($tmp->format('c'), $r[$b]->executed->format('c'));
		Assert::same($a, $r[$a]->path);
		Assert::same($b, $r[$b]->path);

		unlink($a);
		unlink($b);
		return array($a, $b, $r);
	}

	public function testGetToExecute()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		$r = $this->runner->getToExecute(array(
			'_1.sql' => new DibiRow(array(
				'id' => 1,
				'file' => '_1.sql',
				'checksum' => 'c4ca4238a0b923820dcc509a6f75849b',
				'executed' => '2011-11-11',
				'ready' => 1,
			)),
		), $files);

		Assert::same(1, count($r));
		Assert::same(array($files[$b]), $r);
	}

	public function testGetToExecuteRemove()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		Assert::exception(function () use ($files) {
			$this->runner->getToExecute(array(
				'_3.sql' => new DibiRow(array(
					'id' => 1,
					'file' => '_3.sql',
					'checksum' => 'c4ca4238a0b923820dcc509a6f75849b',
					'executed' => '2011-11-11',
					'ready' => 1,
				)),
			), $files);
		}, 'Migration\Exception', '_3.sql se smazal.');
	}

	public function testGetToExecuteChange()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		Assert::exception(function () use ($files) {
			$this->runner->getToExecute(array(
				'_1.sql' => new DibiRow(array(
					'id' => 1,
					'file' => '_1.sql',
					'checksum' => 'bbb',
					'executed' => '2011-11-11',
					'ready' => 1,
				)),
			), $files);
		}, 'Migration\Exception', '_1.sql se zmenil.');
	}

	public function testGetToExecuteNotReady()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		Assert::exception(function () use ($files) {
			$this->runner->getToExecute(array(
				'_1.sql' => new DibiRow(array(
					'id' => 1,
					'file' => '_1.sql',
					'checksum' => 'c4ca4238a0b923820dcc509a6f75849b',
					'executed' => '2011-11-11',
					'ready' => 0,
				)),
			), $files);
		}, 'Migration\Exception', '_1.sql se nedokoncil.');
	}

	public function testExecute()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		$this->driver->shouldReceive('begin')->with(NULL)->andReturn()->once()->ordered();
		$this->qar("INSERT INTO `migrations` (`file`, `checksum`, `executed`) VALUES ('_1.sql', 'c4ca4238a0b923820dcc509a6f75849b', " . $this->driver->escape(new DateTime('now'), Dibi::DATETIME) . ")");
		$this->driver->shouldReceive('getInsertId')->with(NULL)->andReturn(123)->once()->ordered();

		$this->qar("SELECT foobar");
		$this->qar("DO WHATEVER");
		$this->qar("HELLO");

		$this->qar("UPDATE `migrations` SET `ready`=1 WHERE `id` = '123'");
		$this->driver->shouldReceive('commit')->with(NULL)->andReturn()->once()->ordered();

		file_put_contents($a, 'SELECT foobar;DO WHATEVER;HELLO;');
		$count = $this->runner->execute($files[$a]);
		unlink($a);
		Assert::same(3, $count);
	}

	public function testExecuteNoSemicolon()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		$this->driver->shouldReceive('begin')->with(NULL)->andReturn()->once()->ordered();
		$this->qar("INSERT INTO `migrations` (`file`, `checksum`, `executed`) VALUES ('_1.sql', 'c4ca4238a0b923820dcc509a6f75849b', " . $this->driver->escape(new DateTime('now'), Dibi::DATETIME) . ")");
		$this->driver->shouldReceive('getInsertId')->with(NULL)->andReturn(123)->once()->ordered();

		$this->qar("SELECT foobar");
		$this->qar("SELECT WHATEVER");
		$this->qar("HELLO");

		$this->qar("UPDATE `migrations` SET `ready`=1 WHERE `id` = '123'");
		$this->driver->shouldReceive('commit')->with(NULL)->andReturn()->once()->ordered();

		file_put_contents($a, 'SELECT foobar;SELECT WHATEVER;HELLO');
		$count = $this->runner->execute($files[$a]);
		unlink($a);
		Assert::same(3, $count);
	}

	public function testExecuteEmpty()
	{
		list($a, $b, $files) = $this->testGetAllFiles();

		$this->driver->shouldReceive('begin')->with(NULL)->andReturn()->once()->ordered();
		$this->qar("INSERT INTO `migrations` (`file`, `checksum`, `executed`) VALUES ('_1.sql', 'c4ca4238a0b923820dcc509a6f75849b', " . $this->driver->escape(new DateTime('now'), Dibi::DATETIME) . ")");
		$this->driver->shouldReceive('getInsertId')->with(NULL)->andReturn(123)->once()->ordered();

		file_put_contents($a, "\n\t\t\n");

		$ex = Assert::exception(function () use ($files, $a) {
			$this->runner->execute($files[$a]);
		}, 'Migration\Exception');

		Assert::same('_1.sql neobsahuje zadne sql.', $ex->getPrevious()->getMessage());
	}
}

run(new RunnerTest);
