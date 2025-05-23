<?php
/**
 * Maintenance script that handles managing job queue admin tasks
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueue;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
require_once __DIR__ . '/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * Maintenance script that handles managing job queue admin tasks (re-push, delete, ...)
 *
 * @ingroup Maintenance
 */
class ManageJobs extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Perform administrative tasks on a job queue' );
		$this->addOption( 'type', 'Job type', true, true );
		$this->addOption( 'action', 'Queue operation ("delete", "repush-abandoned")', true, true );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$type = $this->getOption( 'type' );
		$action = $this->getOption( 'action' );

		$group = $this->getServiceContainer()->getJobQueueGroup();
		$queue = $group->get( $type );

		if ( $action === 'delete' ) {
			$this->delete( $queue );
		} elseif ( $action === 'repush-abandoned' ) {
			$this->repushAbandoned( $queue );
		} else {
			$this->fatalError( "Invalid action '$action'." );
		}
	}

	private function delete( JobQueue $queue ) {
		$this->output( "Queue has {$queue->getSize()} job(s); deleting...\n" );
		$queue->delete();
		$this->output( "Done; current size is {$queue->getSize()} job(s).\n" );
	}

	private function repushAbandoned( JobQueue $queue ) {
		$cache = $this->getServiceContainer()->getObjectCacheFactory()->getInstance( CACHE_DB );
		$key = $cache->makeGlobalKey( 'last-job-repush', $queue->getDomain(), $queue->getType() );

		$now = wfTimestampNow();
		$lastRepushTime = $cache->get( $key );
		if ( $lastRepushTime === false ) {
			$lastRepushTime = wfTimestamp( TS_MW, 1 ); // include all jobs
		}

		$this->output( "Last re-push time: $lastRepushTime; current time: $now\n" );

		$count = 0;
		$skipped = 0;
		foreach ( $queue->getAllAbandonedJobs() as $job ) {
			/** @var Job $job */
			if ( $job instanceof Job && $job->getQueuedTimestamp() < wfTimestamp( TS_UNIX, $lastRepushTime ) ) {
				++$skipped;
				continue; // already re-pushed in prior round
			}

			$queue->push( $job );
			++$count;

			if ( ( $count % $this->getBatchSize() ) == 0 ) {
				$queue->waitForBackups();
			}
		}

		$cache->set( $key, $now ); // next run will ignore these jobs

		$this->output( "Re-pushed $count job(s) [$skipped skipped].\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = ManageJobs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
