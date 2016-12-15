<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Deletes files that are old enough and are in S3.
 *
 * @package   tool_sssfs
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_sssfs\file_manipulators;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/sssfs/lib.php');

class cleaner extends manipulator {

    /**
     * How long file must exist after
     * duplication before it can be deleted.
     *
     * @var int
     */
    private $consistencydelay;

    /**
     * Whether to delete local files
     * once they are in s3.
     *
     * @var bool
     */
    private $deletelocal;

    /**
     * Cleaner constructor.
     *
     * @param sss_client $client S3 client
     * @param sss_file_system $filesystem S3 file system
     * @param object $config sssfs config.
     */
    public function __construct($client, $filesystem, $config) {
        parent::__construct($client, $filesystem, $config->maxtaskruntime);
        $this->consistencydelay = $config->consistencydelay;
        $this->deletelocal = $config->deletelocal;
    }

    /**
     * Get candidate content hashes for cleaning.
     * Files that are past the consistancy delay
     * and are in state duplicated.
     *
     * @return array candidate contenthashes
     */
    public function get_candidate_content_hashes() {
        global $DB;

        if ($this->deletelocal == 0) {
            return array();
        }

        $sql = 'SELECT SF.contenthash
                FROM {tool_sssfs_filestate} SF
                WHERE SF.timeduplicated <= ? and SF.state = ?';

        $consistancythrehold = time() - $this->consistencydelay;
        $params = array($consistancythrehold, SSS_FILE_STATE_DUPLICATED);
        $contenthashes = $DB->get_fieldset_sql($sql, $params);
        return $contenthashes;
    }

    /**
     * Cleans local file system of candidate hash files.
     *
     * @param  array $candidatehashes content hashes to delete
     */
    public function execute($candidatehashes) {
        global $DB;

        if ($this->deletelocal == 0) {
            return;
        }

        foreach ($candidatehashes as $contenthash) {

            if (time() >= $this->finishtime) {
                break;
            }

            // We find the size here instead of in get_candidate_hashes
            // so we dont have to do a massive group by.
            $sql = 'SELECT max(filesize) from {files} where contenthash = ?';
            $filesize = $DB->get_fieldset_sql($sql, array($contenthash));
            $filesize = reset($filesize);

            try {
                $fileinsss = $this->client->check_file($contenthash, $filesize);
                $this->filesystem->delete_local_file_from_contenthash($contenthash);
                log_file_state($contenthash, SSS_FILE_STATE_EXTERNAL);
            } catch (file_exception $e) {
                mtrace($e);
                continue;
            } catch (S3Exception $e) {
                mtrace($e);
                continue;
            }
        }
    }
}
