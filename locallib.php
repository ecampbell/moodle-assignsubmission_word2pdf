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
 * This file contains the definition for the library class for the Microsoft Word (.docx) file submission plugin
 *
 * Originally written by Davo Smith (assignsubmission_pdf).
 *
 * @package   assignsubmission_word2pdf
 * @copyright 2019 Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignfeedback_editpdf\document_services;
use assignfeedback_editpdf\combined_document;
use assignfeedback_editpdf\pdf;

defined('MOODLE_INTERNAL') || die();
// Development: turn on all debug messages and strict warnings.
define('DEBUG_WORDIMPORT', E_ALL | E_STRICT);
// @codingStandardsIgnoreLine define('DEBUG_WORDIMPORT', 0);

global $CFG;
require_once($CFG->libdir . '/pdflib.php');
// require_once($CFG->libdir . '/tcpdf/tcpdf.php');
require_once($CFG->dirroot . '/mod/assign/submission/word2pdf/lib.php');
// require_once($CFG->dirroot . '/mod/assign/feedback/editpdf/fpdi/fpdi.php');
// require_once($CFG->dirroot . '/mod/assign/feedback/editpdf/lib.php');

/*
 * Library class for Microsoft Word file to PDF conversion.
 *
 * @package   assignsubmission_word2pdf
 * @copyright 2019 Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_word2pdf extends assign_submission_plugin {

    /**
     * Get the name of the file submission plugin
     *
     * @return string
     */
    public function get_name() {
        return get_string('word2pdf', 'assignsubmission_word2pdf');
    }

    /**
     * Get file submission information from the database
     *
     * @param int $submissionid
     * @return mixed
     */
    private function get_word2pdf_submission($submissionid) {
        global $DB;
        return $DB->get_record('assignsubmission_word2pdf', array('submission' => $submissionid));
    }

    /**
     * Get the default settings for the Word file to PDF conversion plugin
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {

        // Is it enabled by default?
        $defaultwordimportsubmissions = get_config('assignsubmission_word2pdf', 'wordimportsubmissions');
        $mform->setDefault('assignsubmission_word2pdf', $defaultwordimportsubmissions);
        // Enabled only if File submissions are enabled.
        $mform->disabledIf('assignsubmission_word2pdf_enabled', 'assignsubmission_file_enabled', 'notchecked');

    }
    /**
     * File format options
     *
     * @return array
     */
    private function get_file_options() {
        $fileoptions = array(
            'subdirs' => 0,
            'accepted_types' => array('*.docx'),
            'return_types' => FILE_INTERNAL
        );
        return $fileoptions;
    }

    /**
     * Count the number of Word files
     *
     * @param int $submissionid
     * @param string $area
     * @return int
     */
    private function count_files($submissionid, $area) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->assignment->get_context()->id, 'assignsubmission_word2pdf',
                                     $area, $submissionid, "id", false);

        return count($files);
    }

    /**
     * Save & convert the Word files to PDFs, and trigger plagiarism plugin if enabled
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data) {
        global $USER;

        debugging(__FUNCTION__ . "(submission->id = $submission->id)", DEBUG_WORDIMPORT);
        // Make a list of Word files to convert to PDF format.
        $fileoptions = $this->get_file_options();
        file_postupdate_standard_filemanager($data, 'wordfiles', $fileoptions, $this->assignment->get_context(),
                                             'assignsubmission_word2pdf', ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT, $submission->id);

        // Plagiarism code event trigger when files are uploaded.
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->assignment->get_context()->id, 'assignsubmission_word2pdf',
                                     ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT, $submission->id, "id", false);

        // Send files to event system.
        // Let Moodle know that an assessable file was uploaded (eg for plagiarism detection).
        $params = array(
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submission->id,
            'other' => array(
                'content' => '',
                'pathnamehashes' => array_keys($files)
            )
        );
        if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
            $params['relateduserid'] = $submission->userid;
        }
        $event = \assignsubmission_file\event\assessable_uploaded::create($params);
        $event->set_legacy_files($files);
        $event->trigger();

        // $wordfilesubmission = $this->get_word2pdf_submission($submission->id);
        if (!$this->assignment->get_instance()->submissiondrafts) {
            // No 'submit assignment' button - need to submit immediately.
            $this->submit_for_grading($submission);
        }

        debugging(__FUNCTION__ . "() -> true", DEBUG_WORDIMPORT);
        return true;
    }

    /**
     * Convert the Word files into PDFs
     *
     * @param stdClass $submission optional details of the submission to process
     * @return void
     */
    public function submit_for_grading($submission = null) {
        global $DB, $USER;

        debugging(__FUNCTION__ . "(submission->id = $submission->id)", DEBUG_WORDIMPORT);
        if (is_null($submission)) {
            if (!empty($this->assignment->get_instance()->teamsubmission)) {
                $submission = $this->assignment->get_group_submission($USER->id, 0, true);
            } else {
                $submission = $this->assignment->get_user_submission($USER->id, true);
            }
        }
        $pagecount = $this->create_submission_pdf($submission);

        // Save the pagecount.
        $submissionpdf = $DB->get_record('assignsubmission_word2pdf', array('assignment' => $submission->assignment,
                                                                      'submission' => $submission->id), 'id');
        $upd = new stdClass();
        $upd->numpages = $pagecount;
        if ($pagecount) {
            $upd->status = ASSIGNSUBMISSION_WORD2PDF_STATUS_SUBMITTED;
        } else {
            $upd->status = ASSIGNSUBMISSION_WORD2PDF_STATUS_EMPTY;
        }
        if ($submissionpdf) {
            $upd->id = $submissionpdf->id;
            $DB->update_record('assignsubmission_word2pdf', $upd);
        } else {
            // This should never really happen, but cope with it anyway.
            $upd->assignment = $submission->assignment;
            $upd->submission = $submission->id;
            $upd->id = $DB->insert_record('assignsubmission_word2pdf', $upd);
        }
        debugging(__FUNCTION__ . "() -> void", DEBUG_WORDIMPORT);
    }

    /**
     * Create a temporary working folder
     *
     * @param int $submissionid Submission ID
     * @return string
     */
    protected function get_temp_folder($submissionid) {
        global $CFG, $USER;

        $tempfolder = $CFG->dataroot . '/temp/assignsubmission_word2pdf/';
        $tempfolder .= sha1("{$submissionid}_{$USER->id}_" . time());
        return $tempfolder;
    }

    /**
     * Convert each of the Word files into HTML using Atto, and from HTML into PDF using the core PDF library
     *
     * @param stdClass $submission Details of the submission to process
     * @return combined_document
     */
    protected function create_submission_pdf(stdClass $submission) {
        global $USER;

        debugging(__FUNCTION__ . "(submission->id = $submission->id)", DEBUG_WORDIMPORT);
        // Create the required temporary folders.
        $temparea = $this->get_temp_folder($submission->id);
        $tempdestarea = $temparea . 'sub';
        if (!file_exists($temparea) || !file_exists($tempdestarea)) {
            if (!mkdir($temparea, 0777, true) || !mkdir($tempdestarea, 0777, true)) {
                $errdata = (object)array('temparea' => $temparea, 'tempdestarea' => $tempdestarea);
                throw new moodle_exception('errortempfolder', 'assignsubmission_word2pdf', '', null, $errdata);
            }
        }

        // Get the Word files submitted.
        $context = $this->assignment->get_context();
        $fs = get_file_storage();
        debugging(__FUNCTION__ . ":" . __LINE__ . ": context->id = $context->id", DEBUG_WORDIMPORT);
        $files = $fs->get_area_files($context->id, 'assignsubmission_file', ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT,
                                     $submission->id, "sortorder, id", false);
        $combinedhtml = "";
        $combinedauthor = "";
        $filecount = 0;

        // Save the newly created files as a combined document list.
        $combineddocument = new combined_document();

        foreach ($files as $file) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ":  process file = " . $file->get_filename(), DEBUG_WORDIMPORT);
            if ($file->get_mimetype() == 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                // Save the Word file to the file system so it can be unzipped and processed.
                if (!$tmpfilename = $file->copy_content_to_temp()) {
                    // Cannot save file.
                    throw new moodle_exception(get_string('errorcreatingfile', 'error', $file->get_filename()));
                }
                $htmltext = assignsubmission_word2pdf_convert_to_xhtml($tmpfilename, $context->id, $submission->id);
                $html = assignsubmission_word2pdf_strip_images($htmltext);
                // $bodyhtml = assignsubmission_word2pdf_get_html_body($htmltext);
                // debugging(__FUNCTION__ . ":" . __LINE__ . ": bodyhtml = " . substr($bodyhtml, 0, 200), DEBUG_WORDIMPORT);
                $mypdf = new pdf();
                $mypdf->SetTitle("Word file name: " . $file->get_filename());
                $mypdf->SetAuthor($file->author);
                $mypdf->startPage();
                $mypdf->writeHTML("<h1>File: " . $file->get_filename() . "</h1>");
                $mypdf->writeHTML($html);
                $mypdf->endPage();
                $pdffilename = basename($file->get_filename(), '.docx') . '.pdf';
                $destfile = $tempdestarea . '/' . $pdffilename;
                debugging(__FUNCTION__ . ":" . __LINE__ . ": destfile = $destfile", DEBUG_WORDIMPORT);
                // Write PDF file to temporary file system location.
                $mypdf->Output($destfile, 'F');

                // Create a place for the combined PDF file, deleting it if it already exists.
                $pdffilerec = new stdClass();
                $pdffilerec->contextid = $context->id;
                $pdffilerec->component = 'assignfeedback_editpdf';
                $pdffilerec->filearea = document_services::COMBINED_PDF_FILEAREA;
                $pdffilerec->itemid = $USER->id;
                $pdffilerec->filepath = '/';
                $pdffilerec->filename = $pdffilename;
                $fs = get_file_storage();
                debugging(__FUNCTION__ . ":" . __LINE__ . ": create new PDF file $pdffilerec->contextid, $pdffilerec->component," .
                            " $pdffilerec->filearea, $pdffilerec->itemid, $pdffilerec->filename", DEBUG_WORDIMPORT);
                $existingfile = $fs->get_file($context->id, $pdffilerec->component, $pdffilerec->filearea, $pdffilerec->itemid,
                                                $pdffilerec->filepath, $pdffilerec->filename);
                if ($existingfile) {
                    // If the file already exists, remove it so it can be updated.
                    debugging(__FUNCTION__ . ":" . __LINE__ . ": delete existing file $pdffilerec->filename", DEBUG_WORDIMPORT);
                    $existingfile->delete();
                }
                // Create a stored file from the PDF file just created.
                $newfile = $fs->create_file_from_pathname($pdffilerec, $destfile);
                $pagecount  = $mypdf->page_count();
                if (!$pagecount) {
                    debugging(__FUNCTION__ . ":" . __LINE__ . ": pagecount = $pagecount", DEBUG_WORDIMPORT);
                    // return 0; // No pages in converted file - this shouldn't happen.
                }
                // Add this PDF file to the list of all PDFs to combine.
                $combineddocument->add_source_file($newfile);
            }
            $filecount++;
        }

        debugging(__FUNCTION__ . "() -> $filecount", DEBUG_WORDIMPORT);
        return $filecount;
    }   // End of create_submission_pdf().

    /**
     * Produce a list of files suitable for export that represent this submission.
     *
     * @param stdClass $submission The submission
     * @param stdClass $user The user
     * @return array - return an array of files indexed by filename
     */
    public function get_files(stdClass $submission, stdClass $user) {
        $result = array();
        $fs = get_file_storage();

        if ($submission->status == ASSIGNSUBMISSION_WORD2PDF_STATUS_SUBMITTED) {
            $files = $fs->get_area_files($this->assignment->get_context()->id, 'assignsubmission_word2pdf',
                                         ASSIGNSUBMISSION_WORD2PDF_FA_FINAL, $submission->id, "timemodified", false);
        } else {
            $files = $fs->get_area_files($this->assignment->get_context()->id, 'assignsubmission_word2pdf',
                                         ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT, $submission->id, "timemodified", false);
        }

        foreach ($files as $file) {
            $result[$file->get_filename()] = $file;
        }
        return $result;
    }

    /**
     * Display the list of files  in the submission status table
     *
     * @param stdClass $submission
     * @param bool $showviewlink
     * @return string
     */
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $SESSION, $DB, $OUTPUT;

        $output = '';
        if (isset($SESSION->assignsubmission_word2pdf_invalid)) {
            $invalidfiles = '';
            foreach ($SESSION->assignsubmission_word2pdf_invalid as $filename) {
                $invalidfiles .= html_writer::tag('p', get_string('invalidpdf', 'assignsubmission_word2pdf', $filename));
            }
            $output .= html_writer::tag('div', $invalidfiles, array('class' => 'assignsubmission_word2pdf_invalid'));
            unset($SESSION->assignsubmission_word2pdf_invalid);
        }

        if (!isset($submission->status)) {
            // For some silly reason, the status is not included in the details when drawing the grading table - so
            // I need to do an extra DB query just to retrieve that information.
            static $submissionstatus = null;
            if (is_null($submissionstatus)) {
                $submissionstatus = $DB->get_records_menu('assign_submission', array('assignment' => $submission->assignment),
                                                          '', 'id, status');
            }
            if (isset($submissionstatus[$submission->id])) {
                $submission->status = $submissionstatus[$submission->id];
            } else {
                $submission->status = ASSIGN_SUBMISSION_STATUS_DRAFT;
            }
        }

        if ($submission->status == ASSIGN_SUBMISSION_STATUS_DRAFT) {
            $output .= $this->assignment->render_area_files('assignsubmission_word2pdf', ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT,
                                                            $submission->id);
        } else {
            if (!$this->is_empty($submission)) {
                $context = $this->assignment->get_context();
                $url = moodle_url::make_pluginfile_url($context->id, 'assignsubmission_word2pdf',
                                                       ASSIGNSUBMISSION_WORD2PDF_FA_FINAL,
                                                       $submission->id, $this->get_subfolder(),
                                                       ASSIGNSUBMISSION_WORD2PDF_FILENAME, true);
                $output .= $OUTPUT->pix_icon('t/download', '');
                $output .= html_writer::link($url, get_string('finalsubmission', 'assignsubmission_word2pdf'));
            }
        }

        return $output;
    }

    /**
     * No full submission view - the summary contains the list of files and that is the whole submission
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        return $this->assignment->render_area_files('assignsubmission_word2pdf', ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT,
                                                    $submission->id);
    }

    /**
     * The assignment has been deleted - cleanup
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;

        // Delete all PDF submission records for this assignment.
        $params = array('assignment' => $this->assignment->get_instance()->id);
        $DB->delete_records('assignsubmission_word2pdf', $params);

        // All files in the module context are automatically deleted - no need to delete each area individually.
        return true;
    }

    /**
     * Formatting for log info
     *
     * @param stdClass $submission The submission
     * @return string
     */
    public function format_for_log(stdClass $submission) {
        // Format the info for each submission plugin add_to_log.
        $filecount = $this->count_files($submission->id, ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT);
        $fileloginfo = '';
        $fileloginfo .= ' the number of file(s) : '.$filecount." file(s).<br>";

        return $fileloginfo;
    }

    /**
     * Return true if there are no submission files
     *
     * @param stdClass $submission The submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        return $this->count_files($submission->id, ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT) == 0;
    }

    /**
     * Get file areas returns a list of areas this plugin stores files
     *
     * @return array - An array of fileareas (keys) and descriptions (values)
     */
    public function get_file_areas() {
        $name = $this->get_name();
        return array(
            ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT => get_string('draftfor', 'assignsubmission_word2pdf', $name),
            ASSIGNSUBMISSION_WORD2PDF_FA_FINAL => get_string('finalfor', 'assignsubmission_word2pdf', $name)
        );
    }

    /**
     * Return a default subfolder path
     *
     * @return string
     */
    protected function get_subfolder() {
        return '/1/';
    }

    /**
     * Copy the student's submission from a previous submission. Used when a student opts to base their resubmission
     * on the last submission.
     *
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     * @return bool
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        global $DB;

        // Copy the files across.
        $contextid = $this->assignment->get_context()->id;
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid,
                                     'assignsubmission_word2pdf',
                                     ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT,
                                     $sourcesubmission->id,
                                     'id',
                                     false);
        foreach ($files as $file) {
            $fieldupdates = array('itemid' => $destsubmission->id);
            $fs->create_file_from_storedfile($fieldupdates, $file);
        }

        // Copy the assignsubmission_file record.
        if ($wordfilesubmission = $this->get_word2pdf_submission($sourcesubmission->id)) {
            unset($wordfilesubmission->id);
            $wordfilesubmission->submission = $destsubmission->id;
            $DB->insert_record('assignsubmission_file', $wordfilesubmission);
        }
        return true;
    }
}
