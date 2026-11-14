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
 * Creates and deletes the demo course of the Teacher & Student Assistant.
 *
 * This file is not part of the plugin and is not in the plugin's install package. It is for
 * test sites: it makes one course with invented students, so that the reports for teachers
 * have something to show. All people in it are invented.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The demo course: what is in it, how it is created and how it is removed.
 *
 * @package    local_darimaa
 * @copyright  2026 Khuygbaatar Ochirkhuyag
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class darimaa_demo {
    /** @var string Short name of the demo course. */
    public const SHORTNAME = 'DEMO-DARIMAA';

    /** @var string ID number of the demo course. */
    public const IDNUMBER = 'darimaa-demo';

    /** @var string Full name of the demo course. */
    public const FULLNAME = 'Демо хичээл: Биологи 101';

    /** @var string Name under which the script remembers what it created, in the site configuration. */
    public const CONFIG = 'darimaa_demo';

    /** @var string Start of every demo username. */
    public const USER_PREFIX = 'demo26';

    /** @var string Username of the demo teacher. */
    public const TEACHER = 'demo26teacher';

    /** @var string Domain of every demo e-mail address. Moodle never sends mail to .invalid addresses. */
    public const EMAIL_DOMAIN = 'example.invalid';

    /** @var int Number of demo students. */
    public const STUDENTS = 30;

    /** @var int Seed of the random numbers: the same seed gives the same data. */
    public const SEED = 20261114;

    /** @var string Name of the first quiz, whose topics are quiz grade items. */
    public const QUIZ1 = 'Сорил 1: Эс, удамшил, экологи';

    /** @var string Name of the second quiz, whose topics are question categories. */
    public const QUIZ2 = 'Сорил 2: Экосистем';

    /** @var string[] Invented first names, one for each student. */
    private const FIRSTNAMES = [
        'Ану', 'Болд', 'Саруул', 'Тэмүүлэн', 'Номин', 'Билгүүн', 'Мишээл', 'Тэлмэн', 'Оюу', 'Хулан',
        'Ганзориг', 'Энхжин', 'Мөнх-Эрдэнэ', 'Сондор', 'Түвшин', 'Уянга', 'Бат-Оргил', 'Цэцэг', 'Дөлгөөн', 'Нарантуяа',
        'Эрдэнэ', 'Солонго', 'Баясгалан', 'Тэргэл', 'Азжаргал', 'Индра', 'Есүхэй', 'Маралмаа', 'Очир', 'Гэрэлмаа',
    ];

    /** @var string[] Invented fathers' names. The last name of every demo user ends with "(демо)". */
    private const LASTNAMES = [
        'Батын', 'Доржийн', 'Ганболдын', 'Сүхийн', 'Цэрэнгийн', 'Пүрэвийн', 'Лхагвын', 'Мягмарын', 'Даваагийн', 'Баярын',
    ];

    /** @var array[] The questions of quiz 1 by topic: each has a statement and whether it is true. */
    private const QUIZ1_TOPICS = [
        'Эсийн бүтэц' => [
            ['Эс бол амьд биеийн бүтэц, үйл ажиллагааны үндсэн нэгж.', true],
            ['Ургамлын эс эсийн ханатай.', true],
            ['Митохондри зөвхөн ургамлын эсэд байдаг.', false],
        ],
        'Удамшил' => [
            ['ДНХ удамшлын мэдээллийг хадгалдаг.', true],
            ['Хүний биеийн эс 23 хос хромосомтой.', true],
            ['Ген бол өөх тосноос тогтдог.', false],
        ],
        'Экологи' => [
            ['Ногоон ургамал фотосинтезээр органик бодис үүсгэдэг.', true],
            ['Хүнсний гинжин хэлхээ үргэлж махчин амьтнаас эхэлдэг.', false],
            ['Задлагч организмууд үхсэн биеийг задалдаг.', true],
        ],
    ];

    /** @var array[] The true or false questions of quiz 2 by topic. */
    private const QUIZ2_TOPICS = [
        'Хүнсний сүлжээ' => [
            ['Үйлдвэрлэгч организмууд нарны энергийг ашигладаг.', true],
            ['Өвсөн тэжээлтнүүд хоёрдогч хэрэглэгч юм.', false],
            ['Хүнсний сүлжээ олон гинжин хэлхээнээс тогтдог.', true],
        ],
        'Популяци' => [
            ['Популяци гэдэг нь нэг зүйлийн бодгалиудын бүлэг.', true],
            ['Популяцийн тоо хэзээ ч өөрчлөгддөггүй.', false],
            ['Нөөц хязгаарлагдмал үед популяцийн өсөлт удааширдаг.', true],
        ],
        'Байгаль хамгаалал' => [
            ['Ойн түймэр биологийн олон янз байдалд нөлөөлдөг.', true],
            ['Тусгай хамгаалалттай газар бол байгалийг хамгаалах нэг арга.', true],
            ['Бэлчээрийн даац хэтрэх нь хөрсөнд нөлөөгүй.', false],
        ],
    ];

    /**
     * Returns the usernames of the demo students. The username is also the student code.
     *
     * @return string[] Usernames keyed by student number, from 1.
     */
    public static function student_usernames(): array {
        $usernames = [];
        for ($number = 1; $number <= self::STUDENTS; $number++) {
            $usernames[$number] = sprintf('%sb%03d', self::USER_PREFIX, $number);
        }
        return $usernames;
    }

    /**
     * Returns what the script remembers about the demo course it created on this site.
     *
     * @return array|null With courseid, teacherid, userids and quizzes, or null when there is no demo course.
     */
    public static function manifest(): ?array {
        $stored = get_config(self::CONFIG, 'manifest');
        $manifest = $stored ? json_decode($stored, true) : null;
        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Lists the reasons why the demo course cannot be created on this site.
     *
     * @return string[] Empty when the course can be created.
     */
    public static function blockers(): array {
        global $DB;

        $reasons = [];
        if (self::manifest()) {
            $reasons[] = 'The demo course already exists: this script created it on this site before.';
        }
        if ($DB->record_exists('course', ['shortname' => self::SHORTNAME])) {
            $reasons[] = 'A course with the short name ' . self::SHORTNAME . ' already exists.';
        }
        if ($DB->record_exists('course', ['idnumber' => self::IDNUMBER])) {
            $reasons[] = 'A course with the ID number ' . self::IDNUMBER . ' already exists.';
        }
        $inuse = self::usernames_in_use();
        if ($inuse) {
            $reasons[] = 'These usernames are already in use: ' . implode(', ', $inuse) . '.';
        }
        return $reasons;
    }

    /**
     * Returns the demo usernames that existing users have.
     *
     * @return string[]
     */
    public static function usernames_in_use(): array {
        global $DB;

        $usernames = array_merge([self::TEACHER], array_values(self::student_usernames()));
        [$insql, $params] = $DB->get_in_or_equal($usernames);
        return $DB->get_fieldset_select('user', 'username', "deleted = 0 AND username $insql", $params, 'username');
    }

    /**
     * Returns the plan for every student: group, activity, submissions and quiz results.
     *
     * The plans are fixed, so that every kind of student is always there: strong students,
     * students who are weak in one topic, students who are weak overall, students who stayed
     * away and students who never came.
     *
     * @return array[] Plans keyed by student number. Each has group, daysago (null = never came), minutes,
     *                 assignments (submitted or not, for the three assignments), quiz1 and quiz2 (for every
     *                 attempt the number of right answers out of 3 in each topic).
     */
    public static function student_plans(): array {
        $plans = [];
        for ($i = 1; $i <= self::STUDENTS; $i++) {
            $plan = [
                'group' => $i % 2,
                'daysago' => null,
                'minutes' => 0,
                'assignments' => [false, false, false],
                'quiz1' => [],
                'quiz2' => [],
            ];

            if ($i <= 8) {
                // Strong students: active, about seven to twelve hours in the course, good results.
                $plan['daysago'] = ($i - 1) % 4;
                $plan['minutes'] = 720 - ($i - 1) * 45;
                $plan['assignments'] = [true, true, $i <= 4];
                $plan['quiz1'] = [[3, 3, 3]];
                $plan['quiz2'] = [[3, 3, 3]];
                if ($i === 3) {
                    $plan['quiz1'] = [[3, 2, 3]];
                } else if ($i === 5) {
                    // Two attempts: the second one is better.
                    $plan['quiz1'] = [[2, 1, 2], [3, 3, 3]];
                } else if ($i === 6) {
                    $plan['quiz1'] = [[2, 3, 3]];
                }
                if ($i === 4) {
                    $plan['quiz2'] = [[3, 3, 2]];
                } else if ($i === 7) {
                    $plan['quiz2'] = [[3, 2, 3]];
                }
            } else if ($i <= 16) {
                // Weak in one topic: fine otherwise.
                $plan['daysago'] = 1 + ($i - 9) % 6;
                $plan['minutes'] = 360 - ($i - 9) * 30;
                $plan['assignments'] = [true, $i % 2 === 0, false];
                $first = [3, 2, 3];
                $first[($i - 9) % 3] = $i % 2;
                $second = [2, 3, 3];
                $second[($i - 8) % 3] = ($i + 1) % 2;
                $plan['quiz1'] = ($i === 12) ? [[1, 1, 1], $first] : [$first];
                $plan['quiz2'] = [$second];
            } else if ($i <= 19) {
                // Weak overall: they come, but work less than an hour and know little.
                $plan['daysago'] = $i - 15;
                $plan['minutes'] = [17 => 55, 18 => 40, 19 => 25][$i];
                $plan['assignments'] = [$i === 17, false, false];
                $plan['quiz1'] = [17 => [[1, 1, 0]], 18 => [[0, 1, 1]], 19 => [[0, 0, 1], [1, 0, 1]]][$i];
                $plan['quiz2'] = [17 => [[1, 0, 1]], 18 => [[1, 1, 0]], 19 => [[0, 1, 0]]][$i];
            } else if ($i <= 25) {
                // Stayed away: the last access is 8 to 20 days ago.
                $plan['daysago'] = [20 => 8, 21 => 10, 22 => 13, 23 => 15, 24 => 18, 25 => 20][$i];
                $plan['minutes'] = [20 => 200, 21 => 150, 22 => 120, 23 => 90, 24 => 60, 25 => 35][$i];
                $plan['assignments'] = [$i <= 23, false, false];
                $plan['quiz1'] = [20 => [[3, 2, 2]], 21 => [[2, 2, 1]], 22 => [[2, 1, 1]], 23 => [[1, 2, 0]]][$i] ?? [];
                $plan['quiz2'] = [20 => [[2, 2, 2]], 21 => [[1, 2, 1]]][$i] ?? [];
            }
            // Students 26 to 30 never opened the course: nothing to add.

            $plans[$i] = $plan;
        }
        return $plans;
    }

    /**
     * Creates the demo course with everything in it.
     *
     * Call {@see self::blockers()} first. Everything happens in one database transaction, so a
     * failure leaves nothing behind.
     *
     * @return array With url, teacher (username), password (shown once, stored nowhere) and counts.
     */
    public static function create(): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/phpunit/classes/util.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');

        mt_srand(self::SEED);
        $now = time();
        // Moodle's own data generators, as the "Make test course" tool of Moodle uses them.
        $generator = \phpunit_util::get_data_generator();
        $admin = get_admin();
        $transaction = $DB->start_delegated_transaction();

        $course = $generator->create_course([
            'fullname' => self::FULLNAME,
            'shortname' => self::SHORTNAME,
            'idnumber' => self::IDNUMBER,
            'category' => \core_course_category::get_default()->id,
            'startdate' => $now - 8 * WEEKSECS,
            'summary' => 'Багш, оюутны туслахыг турших зохиомол хичээл. Бүх оюутан, дүн зохиомол.',
            'summaryformat' => FORMAT_HTML,
            'numsections' => 3,
        ]);
        $context = \core\context\course::instance($course->id);

        // The teacher can log in. The password is random and is only returned to the caller.
        $password = generate_password(16);
        $teacher = $generator->create_user([
            'username' => self::TEACHER,
            'password' => $password,
            'auth' => 'manual',
            'firstname' => 'Багш',
            'lastname' => 'Дэмбэрэлийн (демо)',
            'email' => self::TEACHER . '@' . self::EMAIL_DOMAIN,
            'country' => 'MN',
        ]);
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        // Make sure the printed password really works, whatever the generator stored.
        update_internal_user_password($teacher, $password);
        $teacher = $DB->get_record('user', ['id' => $teacher->id], '*', MUST_EXIST);
        if (!validate_internal_user_password($teacher, $password)) {
            throw new \core\exception\moodle_exception('The password of the demo teacher could not be set.');
        }

        // The students cannot log in: they are ordinary accounts without a usable password, and mail to
        // their addresses is never sent. (Accounts of the type "nologin" are not used, because Moodle 5.0
        // leaves them out of the participants of an assignment.) Their username is their student code.
        $groups = [
            $generator->create_group(['courseid' => $course->id, 'name' => 'Демо бүлэг А']),
            $generator->create_group(['courseid' => $course->id, 'name' => 'Демо бүлэг Б']),
        ];
        $plans = self::student_plans();
        $students = [];
        foreach (self::student_usernames() as $number => $username) {
            $student = $generator->create_user([
                'username' => $username,
                'idnumber' => $username,
                'auth' => 'manual',
                'firstname' => self::FIRSTNAMES[$number - 1],
                'lastname' => self::LASTNAMES[($number - 1) % count(self::LASTNAMES)] . ' (демо)',
                'email' => $username . '@' . self::EMAIL_DOMAIN,
                'country' => 'MN',
            ]);
            // No password can match this value, so nobody can log in as the student.
            $DB->set_field('user', 'password', AUTH_PASSWORD_NOT_CACHED, ['id' => $student->id]);
            $student->password = AUTH_PASSWORD_NOT_CACHED;
            $generator->enrol_user($student->id, $course->id, 'student');
            $generator->create_group_member(['groupid' => $groups[$plans[$number]['group']]->id, 'userid' => $student->id]);
            $students[$number] = $student;
        }

        $submissions = self::create_assignments($generator, $course, $students, $plans, $now);
        [$quizzes, $attempts] = self::create_quizzes($generator, $course, $students, $plans, $now);
        \core\session\manager::set_user($admin);
        $logrows = self::create_activity($course, $context, $students, $plans, $now);

        $transaction->allow_commit();

        // Remember what was created, so that --delete removes exactly this and nothing else.
        set_config('manifest', json_encode([
            'courseid' => (int) $course->id,
            'teacherid' => (int) $teacher->id,
            'userids' => array_map(static fn(\stdClass $user): int => (int) $user->id, array_values($students)),
            'quizzes' => $quizzes,
            'timecreated' => $now,
        ]), self::CONFIG);

        return [
            'url' => (new \core\url('/course/view.php', ['id' => $course->id]))->out(false),
            'teacher' => self::TEACHER,
            'password' => $password,
            'counts' => [
                'students' => count($students),
                'groups' => count($groups),
                'assignments' => 3,
                'submissions' => $submissions,
                'quizzes' => count($quizzes),
                'attempts' => $attempts,
                'log rows' => $logrows,
            ],
        ];
    }

    /**
     * Creates three assignments and the submissions of the students.
     *
     * Two assignments are past their due date and one is still open.
     *
     * @param \testing_data_generator $generator Moodle's data generator.
     * @param \stdClass $course The course.
     * @param \stdClass[] $students The students by number.
     * @param array[] $plans The plans by student number.
     * @param int $now The current time.
     * @return int Number of submissions.
     */
    private static function create_assignments($generator, \stdClass $course, array $students, array $plans, int $now): int {
        $assignments = [
            ['Даалгавар 1: Эсийн бүтцийн зураг', $now - 21 * DAYSECS],
            ['Даалгавар 2: Удамшлын бодлого', $now - 5 * DAYSECS],
            ['Даалгавар 3: Экосистемийн ажиглалт', $now + 7 * DAYSECS],
        ];
        $assigngenerator = $generator->get_plugin_generator('mod_assign');
        $count = 0;

        foreach ($assignments as $index => [$name, $duedate]) {
            $instance = $generator->create_module('assign', [
                'course' => $course->id,
                'name' => $name,
                'intro' => 'Зохиомол даалгавар.',
                'assignsubmission_onlinetext_enabled' => 1,
                'duedate' => $duedate,
            ]);
            $cm = get_coursemodule_from_instance('assign', $instance->id, $course->id, false, MUST_EXIST);
            $assign = new \assign(\core\context\module::instance($cm->id), $cm, $course);

            foreach ($students as $number => $student) {
                if (!$plans[$number]['assignments'][$index]) {
                    continue;
                }
                // The student writes an answer and submits it.
                \core\session\manager::set_user($student);
                $assigngenerator->create_submission([
                    'userid' => $student->id,
                    'cmid' => $cm->id,
                    'onlinetext' => 'Зохиомол хариулт.',
                ]);
                $notices = [];
                $assign->submit_for_grading((object) ['userid' => $student->id], $notices);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Creates the two quizzes and the attempts of the students.
     *
     * Quiz 1 groups its questions into topics with quiz grade items. Quiz 2 has no grade items:
     * its topics are the question categories.
     *
     * @param \testing_data_generator $generator Moodle's data generator.
     * @param \stdClass $course The course.
     * @param \stdClass[] $students The students by number.
     * @param array[] $plans The plans by student number.
     * @param int $now The current time.
     * @return array The course module ids of the two quizzes, and the number of attempts.
     */
    private static function create_quizzes($generator, \stdClass $course, array $students, array $plans, int $now): array {
        global $DB;

        $admin = get_admin();
        $attempts = 0;
        $cmids = [];

        $definitions = [
            'quiz1' => [self::QUIZ1, self::QUIZ1_TOPICS, true, 0],
            'quiz2' => [self::QUIZ2, self::QUIZ2_TOPICS, false, $now + 14 * DAYSECS],
        ];
        foreach ($definitions as $key => [$name, $topics, $usegradeitems, $timeclose]) {
            \core\session\manager::set_user($admin);
            $quiz = $generator->create_module('quiz', [
                'course' => $course->id,
                'name' => $name,
                'intro' => 'Зохиомол сорил.',
                'attempts' => 0,
                'questionsperpage' => 0,
                'timeclose' => $timeclose,
                'grade' => 100,
                'sumgrades' => 9,
            ]);
            $cmids[] = (int) $quiz->cmid;
            $quizcontext = \core\context\module::instance($quiz->cmid);

            // Slot numbers follow the order in which the questions are added.
            $answers = [];
            $slotsbytopic = [];
            $slot = 0;
            // With grade items, all questions can sit in one category.
            $shared = $usegradeitems ? self::create_question_category($quizcontext, 'Сорил 1-ийн асуултууд') : null;
            foreach ($topics as $topic => $questions) {
                $category = $shared ?? self::create_question_category($quizcontext, $topic);
                foreach ($questions as $index => [$statement, $istrue]) {
                    $question = self::create_truefalse_question($category, $topic . ' ' . ($index + 1), $statement, $istrue);
                    quiz_add_quiz_question($question->id, $quiz, 0, 1);
                    $slot++;
                    $answers[$slot] = $istrue ? 1 : 0;
                    $slotsbytopic[$topic][] = $slot;
                }
            }

            if ($usegradeitems) {
                // Quiz grade items group the slots into topics.
                $sortorder = 0;
                foreach ($slotsbytopic as $topic => $slots) {
                    $itemid = $DB->insert_record('quiz_grade_items', (object) [
                        'quizid' => $quiz->id,
                        'sortorder' => ++$sortorder,
                        'name' => $topic,
                    ]);
                    [$insql, $params] = $DB->get_in_or_equal($slots, SQL_PARAMS_NAMED);
                    $DB->set_field_select(
                        'quiz_slots',
                        'quizgradeitemid',
                        $itemid,
                        "quizid = :quizid AND slot $insql",
                        $params + ['quizid' => $quiz->id]
                    );
                }
            }

            foreach ($students as $number => $student) {
                foreach ($plans[$number][$key] as $attemptnumber => $rightbytopic) {
                    $responses = [];
                    foreach (array_values($slotsbytopic) as $topicindex => $slots) {
                        // In a random order, the first questions of the topic are answered right.
                        foreach (self::shuffled($slots) as $position => $topicslot) {
                            $right = $position < $rightbytopic[$topicindex];
                            $responses[$topicslot] = ['answer' => $right ? $answers[$topicslot] : 1 - $answers[$topicslot]];
                        }
                    }
                    ksort($responses);

                    // The attempt is made as the student, the way a student makes one.
                    \core\session\manager::set_user($student);
                    $previous = quiz_get_user_attempts($quiz->id, $student->id, 'all', true);
                    $attempt = quiz_prepare_and_start_new_attempt(
                        \mod_quiz\quiz_settings::create($quiz->id, $student->id),
                        $attemptnumber + 1,
                        $previous ? end($previous) : false
                    );
                    $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
                    $attemptobj->process_submitted_actions(time(), false, $responses);
                    $attemptobj->process_submit(time(), false);
                    $attemptobj->process_grade_submission(time());
                    $attempts++;
                }
            }
        }
        \core\session\manager::set_user($admin);

        return [$cmids, $attempts];
    }

    /**
     * Creates a question category in a quiz.
     *
     * Done with Moodle's question library and not with the question generator, because that
     * generator needs PHPUnit, which a normal site does not have.
     *
     * @param \core\context\module $context The quiz context.
     * @param string $name The category name.
     * @return \stdClass The category.
     */
    private static function create_question_category(\core\context\module $context, string $name): \stdClass {
        global $DB;

        $category = (object) [
            'name' => $name,
            'contextid' => $context->id,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => question_get_top_category($context->id, true)->id,
            'sortorder' => 999,
            'idnumber' => null,
        ];
        $category->id = $DB->insert_record('question_categories', $category);
        return $category;
    }

    /**
     * Creates a true or false question, the way the question editing form saves one.
     *
     * @param \stdClass $category The question category.
     * @param string $name The question name.
     * @param string $statement The statement the student judges.
     * @param bool $istrue Whether the statement is true.
     * @return \stdClass The question.
     */
    private static function create_truefalse_question(
        \stdClass $category,
        string $name,
        string $statement,
        bool $istrue
    ): \stdClass {
        $form = (object) [
            'category' => $category->id,
            'name' => $name,
            'questiontext' => ['text' => $statement, 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'correctanswer' => $istrue ? '1' : '0',
            'feedbacktrue' => ['text' => '', 'format' => FORMAT_HTML],
            'feedbackfalse' => ['text' => '', 'format' => FORMAT_HTML],
            'penalty' => 1,
            'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ];
        $question = (object) [
            'category' => $category->id,
            'qtype' => 'truefalse',
            'createdby' => 0,
            'idnumber' => null,
        ];
        return \question_bank::get_qtype('truefalse')->save_question($question, $form);
    }

    /**
     * Writes when each student last accessed the course, and backdated rows in the standard log.
     *
     * The log rows are sessions of 25 to 60 minutes with an action every five minutes, so that
     * the time estimate of a student is the number of minutes in their plan. Writing straight
     * into these two tables is only done here, for demo data.
     *
     * @param \stdClass $course The course.
     * @param \core\context\course $context The course context.
     * @param \stdClass[] $students The students by number.
     * @param array[] $plans The plans by student number.
     * @param int $now The current time.
     * @return int Number of log rows.
     */
    private static function create_activity(
        \stdClass $course,
        \core\context\course $context,
        array $students,
        array $plans,
        int $now
    ): int {
        global $DB;

        $rows = [];
        foreach ($students as $number => $student) {
            $plan = $plans[$number];
            if ($plan['daysago'] === null) {
                continue;
            }
            $lastaccess = $now - $plan['daysago'] * DAYSECS - 2 * HOURSECS;
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $student->id,
                'courseid' => $course->id,
                'timeaccess' => $lastaccess,
            ]);

            $sessions = [];
            for ($left = $plan['minutes']; $left > 0; $left -= end($sessions)) {
                $sessions[] = min($left, 30 + 5 * mt_rand(1, 6));
            }
            // The newest session ends at the last access. Older ones are days apart, back to the course start.
            $span = $lastaccess - $course->startdate - DAYSECS;
            foreach ($sessions as $index => $minutes) {
                $end = $lastaccess - intdiv($index * $span, count($sessions)) - ($index ? mt_rand(0, 3) * HOURSECS : 0);
                for ($time = $end - $minutes * MINSECS; $time <= $end; $time += 5 * MINSECS) {
                    $rows[] = (object) [
                        'eventname' => '\core\event\course_viewed',
                        'component' => 'core',
                        'action' => 'viewed',
                        'target' => 'course',
                        'crud' => 'r',
                        'edulevel' => 2,
                        'contextid' => $context->id,
                        'contextlevel' => CONTEXT_COURSE,
                        'contextinstanceid' => $course->id,
                        'userid' => $student->id,
                        'courseid' => $course->id,
                        'anonymous' => 0,
                        'other' => 'N;',
                        'timecreated' => $time,
                        'origin' => 'web',
                        'realuserid' => null,
                    ];
                }
            }
        }
        $DB->insert_records('logstore_standard_log', $rows);

        return count($rows);
    }

    /**
     * Shuffles a list with the seeded random numbers, so that the result is the same on every run.
     *
     * @param array $items The list.
     * @return array The same items in a random order.
     */
    private static function shuffled(array $items): array {
        $items = array_values($items);
        for ($index = count($items) - 1; $index > 0; $index--) {
            $other = mt_rand(0, $index);
            [$items[$index], $items[$other]] = [$items[$other], $items[$index]];
        }
        return $items;
    }

    /**
     * Deletes the demo course and the demo users that this script created, and nothing else.
     *
     * A course or user that no longer looks like demo data is left alone and reported.
     *
     * @return array With deleted and skipped: lists of short descriptions.
     */
    public static function delete(): array {
        global $DB;

        $manifest = self::manifest();
        $deleted = [];
        $skipped = [];
        if (!$manifest) {
            return ['deleted' => $deleted, 'skipped' => $skipped];
        }

        $course = $DB->get_record('course', ['id' => $manifest['courseid']]);
        if ($course && $course->shortname === self::SHORTNAME && $course->idnumber === self::IDNUMBER) {
            // The backdated log rows belong to the demo data. Moodle itself keeps logs when a course is deleted.
            $DB->delete_records('logstore_standard_log', ['courseid' => $course->id]);
            $DB->delete_records('user_lastaccess', ['courseid' => $course->id]);
            delete_course($course, false);
            fix_course_sortorder();
            $deleted[] = 'course ' . self::SHORTNAME;
        } else if ($course) {
            $skipped[] = 'course id ' . $course->id . ': it is no longer the demo course';
        }

        $users = 0;
        foreach (array_merge([$manifest['teacherid']], $manifest['userids']) as $userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if (!$user) {
                continue;
            }
            $isdemo = str_starts_with($user->username, self::USER_PREFIX)
                && str_ends_with($user->email, '@' . self::EMAIL_DOMAIN);
            if (!$isdemo) {
                $skipped[] = 'user id ' . $user->id . ': it is no longer a demo user';
                continue;
            }
            delete_user($user);
            $users++;
        }
        $deleted[] = $users . ' demo users';

        unset_config('manifest', self::CONFIG);

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
}
