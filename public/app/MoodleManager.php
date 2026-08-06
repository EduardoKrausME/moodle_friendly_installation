<?php

namespace app;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only Moodle data access plus isolated calls to Moodle core APIs.
 */
class MoodleManager {
    private array $site;
    private array $config;
    private PDO $pdo;
    private string $prefix;

    /**
     * @param array $site
     */
    public function __construct(array $site) {
        $this->site = $site;
        $this->config = self::readConfig((string) ($site["config_file"] ?? ""));
        $this->prefix = self::cleanPrefix((string) ($this->config["prefix"] ?? "mdl_"));
        $this->pdo = $this->connect($this->config);
    }

    /**
     * @return array
     */
    public function userStats(): array {
        $table = $this->table("user");
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN deleted = 0 AND suspended = 0 THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN deleted = 0 AND suspended = 1 THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN deleted = 1 THEN 1 ELSE 0 END) AS deleted
               FROM {$table}
              WHERE id > 1"
        )->fetch();

        return [
            "total" => (int) ($row["total"] ?? 0),
            "active" => (int) ($row["active"] ?? 0),
            "suspended" => (int) ($row["suspended"] ?? 0),
            "deleted" => (int) ($row["deleted"] ?? 0),
        ];
    }

    /**
     * @param string $search
     * @param string $status
     * @param int $page
     * @param int $perpage
     * @param string $sort
     * @param string $direction
     * @return array
     */
    public function users(
        string $search = "",
        string $status = "all",
        int $page = 1,
        int $perpage = 30,
        string $sort = "name",
        string $direction = "asc"
    ): array {
        $page = max(1, $page);
        $perpage = min(100, max(10, $perpage));
        $params = [];
        $conditions = ["u.id > 1"];

        $search = trim($search);
        if ($search !== "") {
            $conditions[] =
                "(u.firstname LIKE :search OR u.lastname LIKE :search OR u.username LIKE :search OR u.email LIKE :search)";
            $params["search"] = "%{$search}%";
        }

        if ($status === "active") {
            $conditions[] = "u.deleted = 0 AND u.suspended = 0";
        } else if ($status === "suspended") {
            $conditions[] = "u.deleted = 0 AND u.suspended = 1";
        } else if ($status === "deleted") {
            $conditions[] = "u.deleted = 1";
        }

        $where = implode(" AND ", $conditions);
        $usertable = $this->table("user");
        $enroltable = $this->table("enrol");
        $userenroltable = $this->table("user_enrolments");

        $countstatement = $this->pdo->prepare("SELECT COUNT(*) FROM {$usertable} u WHERE {$where}");
        $countstatement->execute($params);
        $total = (int) $countstatement->fetchColumn();
        $totalunfiltered = $total;
        if ($search !== "" || $status !== "all") {
            $totalunfiltered = (int) $this->pdo
                ->query("SELECT COUNT(*) FROM {$usertable} u WHERE u.id > 1")
                ->fetchColumn();
        }
        $pages = max(1, (int) ceil($total / $perpage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perpage;

        $direction = strtolower($direction) === "desc" ? "DESC" : "ASC";
        $orderby = match ($sort) {
            "status" => "u.deleted {$direction}, u.suspended {$direction}, u.firstname, u.lastname, u.id",
            "auth" => "u.auth {$direction}, u.firstname, u.lastname, u.id",
            "courses" => "coursecount {$direction}, u.firstname, u.lastname, u.id",
            "lastaccess" => "u.lastaccess {$direction}, u.id {$direction}",
            default => "u.firstname {$direction}, u.lastname {$direction}, u.id {$direction}",
        };

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.auth,
                       u.confirmed, u.suspended, u.deleted, u.lastaccess, u.timecreated,
                       COUNT(DISTINCT CASE WHEN ue.status = 0 AND e.status = 0 THEN e.courseid END) AS coursecount
                  FROM {$usertable} u
             LEFT JOIN {$userenroltable} ue ON ue.userid = u.id
             LEFT JOIN {$enroltable} e ON e.id = ue.enrolid
                 WHERE {$where}
              GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.auth,
                       u.confirmed, u.suspended, u.deleted, u.lastaccess, u.timecreated
              ORDER BY {$orderby}
                 LIMIT {$perpage} OFFSET {$offset}";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = $this->formatUserRow($row);
        }

        return [
            "items" => $items,
            "total" => $total,
            "total_unfiltered" => $totalunfiltered,
            "page" => $page,
            "pages" => $pages,
            "has_previous" => $page > 1,
            "previous_page" => max(1, $page - 1),
            "has_next" => $page < $pages,
            "next_page" => min($pages, $page + 1),
            "summary" => t("moodle_users.pagination", ["page" => $page, "pages" => $pages, "total" => $total]),
        ];
    }

    /**
     * @param int $userid
     * @return array|null
     */
    public function user(int $userid): ?array {
        $usertable = $this->table("user");
        $sql = "SELECT u.*,
                       (SELECT COUNT(DISTINCT e.courseid)
                          FROM " . $this->table("user_enrolments") . " ue
                          JOIN " . $this->table("enrol") . " e ON e.id = ue.enrolid
                         WHERE ue.userid = u.id AND ue.status = 0 AND e.status = 0) AS coursecount
                  FROM {$usertable} u
                 WHERE u.id = :id AND u.id > 1";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(["id" => $userid]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }

        $user = $this->formatUserRow($row);
        $user["city"] = (string) ($row["city"] ?? "");
        $user["country"] = (string) ($row["country"] ?? "");
        $user["lang"] = (string) ($row["lang"] ?? "");
        $user["timezone"] = (string) ($row["timezone"] ?? "");
        $user["description"] = trim(strip_tags((string) ($row["description"] ?? "")));
        $user["firstaccess_formatted"] = self::formatTime((int) ($row["firstaccess"] ?? 0));
        $user["lastlogin_formatted"] = self::formatTime((int) ($row["lastlogin"] ?? 0));
        $user["edit_url"] = $this->moodleUrl("/user/editadvanced.php?id={$userid}&course=1");
        $user["profile_url"] = $this->moodleUrl("/user/profile.php?id={$userid}");
        $user["preferences"] = $this->userPreferences($userid);
        $user["has_preferences"] = !empty($user["preferences"]);
        $user["preferences_count"] = count($user["preferences"]);
        $user["custom_fields"] = $this->userCustomFields($userid);
        $user["has_custom_fields"] = !empty($user["custom_fields"]);
        $user["custom_fields_count"] = count($user["custom_fields"]);
        $user["courses"] = $this->userCourses($userid);
        $user["has_courses"] = !empty($user["courses"]);
        $user["can_manage"] = $userid > 2;

        return $user;
    }

    /**
     * @return array
     */
    public function courseStats(): array {
        $coursetable = $this->table("course");
        $modulestable = $this->table("course_modules");
        $row = $this->pdo->query(
            "SELECT (SELECT COUNT(*) FROM {$coursetable} WHERE id > 1) AS total,
                    (SELECT COUNT(*) FROM {$coursetable} WHERE id > 1 AND visible = 1) AS visible,
                    (SELECT COUNT(*) FROM {$coursetable} WHERE id > 1 AND visible = 0) AS hidden,
                    (SELECT COUNT(*) FROM {$modulestable} cm
                       JOIN {$coursetable} c ON c.id = cm.course
                      WHERE c.id > 1 AND cm.deletioninprogress = 0) AS activities"
        )->fetch();

        return [
            "total" => (int) ($row["total"] ?? 0),
            "visible" => (int) ($row["visible"] ?? 0),
            "hidden" => (int) ($row["hidden"] ?? 0),
            "activities" => (int) ($row["activities"] ?? 0),
        ];
    }

    /**
     * @param string $search
     * @param string $visibility
     * @param int $page
     * @param int $perpage
     * @param string $sort
     * @param string $direction
     * @return array
     */
    public function courses(
        string $search = "",
        string $visibility = "all",
        int $page = 1,
        int $perpage = 24,
        string $sort = "course",
        string $direction = "asc"
    ): array {
        $page = max(1, $page);
        $perpage = min(100, max(8, $perpage));
        $params = [];
        $conditions = ["c.id > 1"];

        $search = trim($search);
        if ($search !== "") {
            $conditions[] = "(c.fullname LIKE :search OR c.shortname LIKE :search OR c.idnumber LIKE :search)";
            $params["search"] = "%{$search}%";
        }
        if ($visibility === "visible") {
            $conditions[] = "c.visible = 1";
        } else if ($visibility === "hidden") {
            $conditions[] = "c.visible = 0";
        }

        $where = implode(" AND ", $conditions);
        $coursetable = $this->table("course");
        $categorytable = $this->table("course_categories");
        $countstatement = $this->pdo->prepare("SELECT COUNT(*) FROM {$coursetable} c WHERE {$where}");
        $countstatement->execute($params);
        $total = (int) $countstatement->fetchColumn();
        $totalunfiltered = $total;
        if ($search !== "" || $visibility !== "all") {
            $totalunfiltered = (int) $this->pdo
                ->query("SELECT COUNT(*) FROM {$coursetable} c WHERE c.id > 1")
                ->fetchColumn();
        }
        $pages = max(1, (int) ceil($total / $perpage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perpage;

        $direction = strtolower($direction) === "desc" ? "DESC" : "ASC";
        $orderby = match ($sort) {
            "visibility" => "c.visible {$direction}, c.fullname, c.id",
            "category" => "cc.name {$direction}, c.fullname, c.id",
            "participants" => "participantcount {$direction}, c.fullname, c.id",
            "activities" => "activitycount {$direction}, c.fullname, c.id",
            "format" => "c.format {$direction}, c.fullname, c.id",
            default => "c.fullname {$direction}, c.id {$direction}",
        };

        $sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, c.visible, c.format,
                       c.startdate, c.enddate, c.timecreated, c.timemodified,
                       cc.name AS categoryname,
                       (SELECT COUNT(DISTINCT ue.userid)
                          FROM " . $this->table("enrol") . " e
                          JOIN " . $this->table("user_enrolments") . " ue ON ue.enrolid = e.id
                         WHERE e.courseid = c.id AND e.status = 0 AND ue.status = 0) AS participantcount,
                       (SELECT COUNT(*) FROM " . $this->table("course_modules") . " cm
                         WHERE cm.course = c.id AND cm.deletioninprogress = 0) AS activitycount
                  FROM {$coursetable} c
             LEFT JOIN {$categorytable} cc ON cc.id = c.category
                 WHERE {$where}
              ORDER BY {$orderby}
                 LIMIT {$perpage} OFFSET {$offset}";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = $this->formatCourseRow($row);
        }

        return [
            "items" => $items,
            "total" => $total,
            "total_unfiltered" => $totalunfiltered,
            "page" => $page,
            "pages" => $pages,
            "has_previous" => $page > 1,
            "previous_page" => max(1, $page - 1),
            "has_next" => $page < $pages,
            "next_page" => min($pages, $page + 1),
            "summary" => t("moodle_courses.pagination", ["page" => $page, "pages" => $pages, "total" => $total]),
        ];
    }

    /**
     * @param int $courseid
     * @param string $participantsearch
     * @return array|null
     */
    public function course(int $courseid, string $participantsearch = ""): ?array {
        $sql = "SELECT c.*, cc.name AS categoryname,
                       (SELECT COUNT(DISTINCT ue.userid)
                          FROM " . $this->table("enrol") . " e
                          JOIN " . $this->table("user_enrolments") . " ue ON ue.enrolid = e.id
                         WHERE e.courseid = c.id AND e.status = 0 AND ue.status = 0) AS participantcount,
                       (SELECT COUNT(*) FROM " . $this->table("course_modules") . " cm
                         WHERE cm.course = c.id AND cm.deletioninprogress = 0) AS activitycount
                  FROM " . $this->table("course") . " c
             LEFT JOIN " . $this->table("course_categories") . " cc ON cc.id = c.category
                 WHERE c.id = :id AND c.id > 1";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(["id" => $courseid]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }

        $course = $this->formatCourseRow($row);
        $course["summary"] = trim(strip_tags((string) ($row["summary"] ?? "")));
        $course["lang"] = (string) ($row["lang"] ?? "");
        $course["theme"] = (string) ($row["theme"] ?? "");
        $course["maxbytes"] = (int) ($row["maxbytes"] ?? 0);
        $course["enablecompletion"] = !empty($row["enablecompletion"]);
        $course["showgrades"] = !empty($row["showgrades"]);
        $course["edit_url"] = $this->moodleUrl("/course/edit.php?id={$courseid}&returnto=catmanage");
        $course["view_url"] = $this->moodleUrl("/course/view.php?id={$courseid}");
        $course["native_participants_url"] = $this->moodleUrl("/user/index.php?id={$courseid}");
        $course["custom_fields"] = $this->courseCustomFields($courseid);
        $course["has_custom_fields"] = !empty($course["custom_fields"]);
        $course["roles"] = $this->roleOptions();
        $course["participants"] = $this->participants($courseid, $participantsearch);
        foreach ($course["participants"] as $index => $participant) {
            $roleoptions = [];
            foreach ($course["roles"] as $role) {
                $role["selected"] = (int) $role["id"] === (int) $participant["role_id"];
                $roleoptions[] = $role;
            }
            $course["participants"][$index]["role_options"] = $roleoptions;
            $course["participants"][$index]["courseid"] = $courseid;
        }
        $course["has_participants"] = !empty($course["participants"]);

        return $course;
    }

    /**
     * @param int $courseid
     * @param string $search
     * @return array
     */
    public function participants(int $courseid, string $search = ""): array {
        $params = ["courseid" => $courseid];
        $searchsql = "";
        if (trim($search) !== "") {
            $searchsql =
                " AND (u.firstname LIKE :search OR u.lastname LIKE :search OR u.username LIKE :search OR u.email LIKE :search)";
            $params["search"] = "%" . trim($search) . "%";
        }

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.suspended, u.deleted,
                       MIN(ue.status) AS enrolstatus, MIN(ue.timecreated) AS enrolledat,
                       MIN(ue.timestart) AS timestart, MAX(ue.timeend) AS timeend
                  FROM " . $this->table("user") . " u
                  JOIN " . $this->table("user_enrolments") . " ue ON ue.userid = u.id
                  JOIN " . $this->table("enrol") . " e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid {$searchsql}
              GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.suspended, u.deleted
              ORDER BY u.firstname, u.lastname
                 LIMIT 100";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $roles = $this->participantRoles($courseid, (int) $row["id"]);
            $row["roles"] = $roles;
            $row["role_names"] = implode(", ", array_column($roles, "name"));
            $row["role_id"] = (int) ($roles[0]["id"] ?? 0);
            $row["fullname"] = trim((string) $row["firstname"] . " " . (string) $row["lastname"]);
            $row["initials"] = self::initials($row["fullname"] ?: (string) $row["username"]);
            $row["is_active"] = empty($row["deleted"]) && empty($row["suspended"]) && (int) $row["enrolstatus"] === 0;
            $row["status_label"] = $row["is_active"] ? t("status.active") : t("status.disabled");
            $row["status_class"] = $row["is_active"] ? "ok" : "warning";
            $row["enrolled_at"] = self::formatTime((int) $row["enrolledat"]);
            $row["time_end"] = self::formatTime((int) $row["timeend"]);
            $row["profile_url"] = $this->moodleUrl("/user/view.php?id={$row["id"]}&course={$courseid}");
            $items[] = $row;
        }
        return $items;
    }

    /**
     * @param int $courseid
     * @param string $search
     * @return array
     */
    public function availableUsers(int $courseid, string $search): array {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }
        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email
                  FROM " . $this->table("user") . " u
                 WHERE u.id > 1 AND u.deleted = 0
                   AND (u.firstname LIKE :search OR u.lastname LIKE :search OR u.username LIKE :search OR u.email LIKE :search)
                   AND NOT EXISTS (
                       SELECT 1 FROM " . $this->table("user_enrolments") . " ue
                       JOIN " . $this->table("enrol") . " e ON e.id = ue.enrolid
                       WHERE ue.userid = u.id AND e.courseid = :courseid
                   )
              ORDER BY u.firstname, u.lastname
                 LIMIT 20";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(["search" => "%{$search}%", "courseid" => $courseid]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = [
                "id" => (int) $row["id"],
                "fullname" => trim((string) $row["firstname"] . " " . (string) $row["lastname"]),
                "username" => (string) $row["username"],
                "email" => (string) $row["email"],
            ];
        }
        return $items;
    }

    /**
     * @return array
     */
    public function roleOptions(): array {
        $sql = "SELECT id, name, shortname, archetype
                  FROM " . $this->table("role") . "
                 WHERE archetype IN ('student', 'teacher', 'editingteacher')
              ORDER BY CASE archetype WHEN 'student' THEN 1 WHEN 'editingteacher' THEN 2 ELSE 3 END, sortorder";
        $items = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $name = trim((string) $row["name"]);
            if ($name === "") {
                $name = self::roleLabel((string) $row["archetype"], (string) $row["shortname"]);
            }
            $items[] = ["id" => (int) $row["id"], "name" => $name, "archetype" => (string) $row["archetype"]];
        }
        return $items;
    }

    /**
     * @param string $scope
     * @param int|null $entityid
     * @return array
     */
    public function reports(string $scope, ?int $entityid = null): array {
        $catalog = $scope === "users" ? self::userReportCatalog() : self::courseReportCatalog();
        if (!$this->tableExists("local_kopere_bi_page")) {
            return [];
        }

        $refkeys = array_keys($catalog);
        $placeholders = implode(",", array_fill(0, count($refkeys), "?"));
        $sql = "SELECT id, refkey FROM " . $this->table("local_kopere_bi_page") . " WHERE refkey IN ({$placeholders})";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($refkeys);
        $pages = [];
        foreach ($statement->fetchAll() as $row) {
            $pages[(string) $row["refkey"]] = (int) $row["id"];
        }

        $items = [];
        foreach ($catalog as $refkey => $definition) {
            if (!isset($pages[$refkey])) {
                continue;
            }
            $params = "classname=dashboard&method=preview&page_id={$pages[$refkey]}";
            if ($entityid && $scope === "users") {
                $params .= "&userid={$entityid}";
            } else if ($entityid && $scope === "courses") {
                $params .= "&courseid={$entityid}";
            }
            $items[] = [
                "title" => $definition[0],
                "description" => $definition[1],
                "group" => $definition[2],
                "url" => $this->moodleUrl("/local/kopere_bi/index.php?{$params}"),
            ];
        }
        return $items;
    }

    /**
     * Execute one mutation after Moodle itself has been bootstrapped in a separate PHP process.
     *
     * @param string $action
     * @param array $params
     * @return array
     */
    public function execute(string $action, array $params): array {
        $allowed =
            ["user_suspend", "user_activate", "user_delete", "user_restore", "participant_add", "participant_update", "participant_remove"];
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException(t("moodle_manager.invalid_action"));
        }
        if (!function_exists("proc_open")) {
            throw new RuntimeException(t("moodle_manager.process_unavailable"));
        }

        $script = dirname(__DIR__, 2) . "/bin/moodle-admin-cli.php";
        if (!is_file($script)) {
            throw new RuntimeException(t("moodle_manager.bridge_missing"));
        }
        $phpbin = (string) (app_config("php_bin") ?: PHP_BINARY);
        $payload = base64_encode(json_encode([
            "moodle_dir" => (string) ($this->site["moodle_dir"] ?? ""),
            "action" => $action,
            "params" => $params,
        ], JSON_THROW_ON_ERROR));

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $process = proc_open([$phpbin, $script, $payload], $descriptors, $pipes, dirname($script));
        if (!is_resource($process)) {
            throw new RuntimeException(t("moodle_manager.process_failed"));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $result = null;
        $outputlines = preg_split('/\R/', trim((string) $stdout)) ?: [];
        for ($index = count($outputlines) - 1; $index >= 0; $index--) {
            $candidate = json_decode(trim($outputlines[$index]), true);
            if (is_array($candidate) && array_key_exists("ok", $candidate)) {
                $result = $candidate;
                break;
            }
        }
        if ($status !== 0 || !is_array($result) || empty($result["ok"])) {
            $message = is_array($result) ? (string) ($result["error"] ?? "") : "";
            if ($message === "") {
                $message = trim((string) $stderr) ?: t("moodle_manager.process_failed");
            }
            throw new RuntimeException($message);
        }
        return $result;
    }

    /**
     * @param string $path
     * @return string
     */
    public function moodleUrl(string $path): string {
        return self::adminUrl($this->site, $path);
    }

    /**
     * Build an authenticated Moodle URL that redirects to the requested path.
     *
     * @param array $site
     * @param string $path
     * @return string
     */
    public static function adminUrl(array $site, string $path): string {
        $destination = "/" . ltrim($path, "/");
        $loginurl = (string) ($site["sso_url"] ?? "");
        if ($loginurl === "") {
            $base = rtrim((string) ($site["url"] ?? ""), "/");
            $loginurl = $base . "/moodle-logar-admin.php";
        }
        $separator = str_contains($loginurl, "?") ? "&" : "?";
        return $loginurl . $separator . "to=" . rawurlencode($destination);
    }

    /**
     * @return string
     */
    public function createUserUrl(): string {
        return $this->moodleUrl("/user/editadvanced.php?id=-1");
    }

    /**
     * @return string
     */
    public function createCourseUrl(): string {
        return $this->moodleUrl("/course/edit.php?category=1&returnto=topcat");
    }

    /**
     * @param array $row
     * @return array
     */
    private function formatUserRow(array $row): array {
        $fullname = trim((string) ($row["firstname"] ?? "") . " " . (string) ($row["lastname"] ?? ""));
        if ($fullname === "") {
            $fullname = (string) ($row["username"] ?? "");
        }
        $deleted = !empty($row["deleted"]);
        $suspended = !$deleted && !empty($row["suspended"]);
        $status = $deleted ? "deleted" : ($suspended ? "suspended" : "active");

        return array_merge($row, [
            "id" => (int) $row["id"],
            "fullname" => $fullname,
            "initials" => self::initials($fullname),
            "coursecount" => (int) ($row["coursecount"] ?? 0),
            "status" => $status,
            "status_label" => t("moodle_users.status_{$status}"),
            "status_class" => $status === "active" ? "ok" : ($status === "suspended" ? "warning" : "danger"),
            "is_active" => $status === "active",
            "is_suspended" => $status === "suspended",
            "is_deleted" => $status === "deleted",
            "lastaccess_formatted" => self::formatTime((int) ($row["lastaccess"] ?? 0)),
            "created_formatted" => self::formatTime((int) ($row["timecreated"] ?? 0)),
        ]);
    }

    /**
     * @param array $row
     * @return array
     */
    private function formatCourseRow(array $row): array {
        $visible = !empty($row["visible"]);
        return array_merge($row, [
            "id" => (int) $row["id"],
            "fullname" => (string) $row["fullname"],
            "shortname" => (string) $row["shortname"],
            "categoryname" => (string) ($row["categoryname"] ?? t("moodle_courses.no_category")),
            "participantcount" => (int) ($row["participantcount"] ?? 0),
            "activitycount" => (int) ($row["activitycount"] ?? 0),
            "is_visible" => $visible,
            "visibility_label" => $visible ? t("moodle_courses.visible") : t("moodle_courses.hidden"),
            "visibility_class" => $visible ? "ok" : "warning",
            "startdate_formatted" => self::formatTime((int) ($row["startdate"] ?? 0), true),
            "enddate_formatted" => self::formatTime((int) ($row["enddate"] ?? 0), true),
            "modified_formatted" => self::formatTime((int) ($row["timemodified"] ?? 0)),
        ]);
    }

    /**
     * @param int $userid
     * @return array
     */
    private function userPreferences(int $userid): array {
        if (!$this->tableExists("user_preferences")) {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT name, value FROM " . $this->table("user_preferences") . " WHERE userid = :userid ORDER BY name"
        );
        $statement->execute(["userid" => $userid]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = [
                "name" => $row["name"],
                "value" => $row["value"],
                "is_sensitive" => $sensitive,
            ];
        }
        return $items;
    }

    /**
     * @param int $userid
     * @return array
     */
    private function userCustomFields(int $userid): array {
        if (!$this->tableExists("user_info_field") || !$this->tableExists("user_info_data")) {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT f.name, f.shortname, d.data AS value
               FROM " . $this->table("user_info_field") . " f
          LEFT JOIN " . $this->table("user_info_data") . " d ON d.fieldid = f.id AND d.userid = :userid
           ORDER BY f.sortorder, f.id"
        );
        $statement->execute(["userid" => $userid]);
        return array_map(static fn(array $row): array => [
            "name" => (string) $row["name"],
            "shortname" => (string) $row["shortname"],
            "value" => (string) ($row["value"] ?? ""),
        ], $statement->fetchAll());
    }

    /**
     * @param int $userid
     * @return array
     */
    private function userCourses(int $userid): array {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT c.id, c.fullname, c.shortname
               FROM " . $this->table("course") . " c
               JOIN " . $this->table("enrol") . " e ON e.courseid = c.id
               JOIN " . $this->table("user_enrolments") . " ue ON ue.enrolid = e.id
              WHERE ue.userid = :userid
           ORDER BY c.fullname
              LIMIT 50"
        );
        $statement->execute(["userid" => $userid]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $row["url"] = $this->moodleUrl("/course/view.php?id=" . (int) $row["id"]);
            $items[] = $row;
        }
        return $items;
    }

    /**
     * @param int $courseid
     * @return array
     */
    private function courseCustomFields(int $courseid): array {
        if (!$this->tableExists("customfield_field") || !$this->tableExists("customfield_data")) {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT f.name, f.shortname, COALESCE(d.value, d.charvalue, d.intvalue, d.decvalue) AS value
               FROM " . $this->table("customfield_field") . " f
          LEFT JOIN " . $this->table("customfield_data") . " d ON d.fieldid = f.id AND d.instanceid = :courseid
           ORDER BY f.id"
        );
        try {
            $statement->execute(["courseid" => $courseid]);
            return array_map(static fn(array $row): array => [
                "name" => (string) $row["name"],
                "shortname" => (string) $row["shortname"],
                "value" => (string) ($row["value"] ?? ""),
            ], $statement->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    private function participantRoles(int $courseid, int $userid): array {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT r.id, r.name, r.shortname, r.archetype
               FROM " . $this->table("role") . " r
               JOIN " . $this->table("role_assignments") . " ra ON ra.roleid = r.id
               JOIN " . $this->table("context") . " ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid AND ctx.contextlevel = 50 AND ctx.instanceid = :courseid"
        );
        $statement->execute(["userid" => $userid, "courseid" => $courseid]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $name = trim((string) $row["name"]);
            if ($name === "") {
                $name = self::roleLabel((string) $row["archetype"], (string) $row["shortname"]);
            }
            $items[] = ["id" => (int) $row["id"], "name" => $name];
        }
        return $items;
    }

    /**
     * @param array $config
     * @return PDO
     */
    private function connect(array $config): PDO {
        $dbtype = strtolower((string) ($config["dbtype"] ?? "mysqli"));
        $host = (string) ($config["dbhost"] ?? "localhost");
        $port = (string) ($config["dbport"] ?? "");
        $dbname = (string) ($config["dbname"] ?? "");
        $user = (string) ($config["dbuser"] ?? "");
        $pass = (string) ($config["dbpass"] ?? "");
        if ($dbname === "") {
            throw new RuntimeException(t("moodle_manager.database_missing"));
        }

        if (in_array($dbtype, ["pgsql", "postgres", "postgresql"], true)) {
            $dsn = "pgsql:host={$host};dbname={$dbname}" . ($port !== "" ? ";port={$port}" : "");
        } else {
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4" . ($port !== "" ? ";port={$port}" : "");
        }

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(t("moodle_manager.database_failed", ["message" => $exception->getMessage()]));
        }
    }

    /**
     * @param string $configfile
     * @return array
     */
    private static function readConfig(string $configfile): array {
        if ($configfile === "" || !is_readable($configfile)) {
            throw new RuntimeException(t("moodle_manager.config_missing"));
        }
        $content = file_get_contents($configfile);
        if (!is_string($content) || $content === "") {
            throw new RuntimeException(t("moodle_manager.config_missing"));
        }

        $values = [];
        foreach (["wwwroot", "dbtype", "dbhost", "dbname", "dbuser", "dbpass", "prefix"] as $key) {
            $quoted = preg_quote($key, "/");
            if (preg_match('/\$CFG->' . $quoted . '\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $content, $matches)) {
                $values[$key] = stripcslashes($matches[2]);
            }
        }
        foreach (["dbport"] as $key) {
            $quoted = preg_quote($key, "/");
            if (preg_match('/[\'\"]' . $quoted . '[\'\"]\s*=>\s*([\'\"])((?:\\\\.|(?!\1).)*)\1/s', $content, $matches)) {
                $values[$key] = stripcslashes($matches[2]);
            }
        }
        return $values;
    }

    /**
     * @param string $name
     * @return string
     */
    private function table(string $name): string {
        if (!preg_match('/^[a-z0-9_]+$/i', $name)) {
            throw new RuntimeException(t("moodle_manager.invalid_table"));
        }
        return $this->prefix . $name;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function tableExists(string $name): bool {
        try {
            $this->pdo->query("SELECT 1 FROM " . $this->table($name) . " WHERE 1 = 0");
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param string $prefix
     * @return string
     */
    private static function cleanPrefix(string $prefix): string {
        if (!preg_match('/^[a-z0-9_]+$/i', $prefix)) {
            throw new RuntimeException(t("moodle_manager.invalid_prefix"));
        }
        return $prefix;
    }

    /**
     * @param int $time
     * @param bool $dateonly
     * @return string
     */
    private static function formatTime(int $time, bool $dateonly = false): string {
        if ($time <= 0) {
            return t("moodle_manager.never");
        }
        return date($dateonly ? "d/m/Y" : "d/m/Y H:i", $time);
    }

    /**
     * @param string $name
     * @return string
     */
    private static function initials(string $name): string {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));
        if (!$parts) {
            return "?";
        }
        $initials = mb_substr($parts[0], 0, 1);
        if (count($parts) > 1) {
            $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
        }
        return mb_strtoupper($initials);
    }

    /**
     * @param string $archetype
     * @param string $shortname
     * @return string
     */
    private static function roleLabel(string $archetype, string $shortname): string {
        return match ($archetype) {
            "student" => t("moodle_courses.role_student"),
            "editingteacher" => t("moodle_courses.role_editingteacher"),
            "teacher" => t("moodle_courses.role_teacher"),
            default => $shortname,
        };
    }

    /**
     * @return array
     */
    private static function userReportCatalog(): array {
        return [
            "active_students" => [
                t("moodle_reports.active_students"), t("moodle_reports.active_students_desc"), t(
                    "moodle_reports.group_students"
                ),
            ],
            "student_course_progress_overview" => [
                t("moodle_reports.student_progress"), t(
                    "moodle_reports.student_progress_desc"
                ), t("moodle_reports.group_students"),
            ],
            "students_without_course_access" => [
                t("moodle_reports.without_access"), t("moodle_reports.without_access_desc"), t(
                    "moodle_reports.group_students"
                ),
            ],
            "inactive_students_by_course" => [
                t("moodle_reports.inactive_students"), t("moodle_reports.inactive_students_desc"), t(
                    "moodle_reports.group_students"
                ),
            ],
            "students_at_risk" => [
                t("moodle_reports.students_at_risk"), t("moodle_reports.students_at_risk_desc"), t(
                    "moodle_reports.group_students"
                ),
            ],
            "student_enrolments_ending_soon" => [
                t("moodle_reports.enrolments_ending"), t(
                    "moodle_reports.enrolments_ending_desc"
                ), t("moodle_reports.group_enrolments"),
            ],
            "student_course_completion_status" => [
                t("moodle_reports.completion_status"), t(
                    "moodle_reports.completion_status_desc"
                ), t("moodle_reports.group_students"),
            ],
            "student_registrations_by_month" => [
                t("moodle_reports.registrations_month"), t(
                    "moodle_reports.registrations_month_desc"
                ), t("moodle_reports.group_students"),
            ],
            "student_profile_distribution" => [
                t("moodle_reports.profile_distribution"), t(
                    "moodle_reports.profile_distribution_desc"
                ), t("moodle_reports.group_students"),
            ],
            "kbi_page_user_profile_information_learn" => [
                t("moodle_reports.profile_information"), t(
                    "moodle_reports.profile_information_desc"
                ), t("moodle_reports.group_users"),
            ],
            "kbi_page_user_site_use_summary" => [
                t("moodle_reports.site_use"), t("moodle_reports.site_use_desc"), t(
                    "moodle_reports.group_users"
                ),
            ],
            "kbi_page_user_status" => [
                t("moodle_reports.user_status"), t("moodle_reports.user_status_desc"), t(
                    "moodle_reports.group_users"
                ),
            ],
        ];
    }

    /**
     * @return array
     */
    private static function courseReportCatalog(): array {
        return [
            "courses" => [t("moodle_reports.courses"), t("moodle_reports.courses_desc"), t("moodle_reports.group_courses")],
            "report_page_009" => [
                t("moodle_reports.course_modules"), t("moodle_reports.course_modules_desc"), t(
                    "moodle_reports.group_courses"
                ),
            ],
            "kbi_page_course_access_details" => [
                t("moodle_reports.course_access"), t("moodle_reports.course_access_desc"), t(
                    "moodle_reports.group_courses"
                ),
            ],
            "kbi_page_course_activity_overview" => [
                t("moodle_reports.activity_overview"), t(
                    "moodle_reports.activity_overview_desc"
                ), t("moodle_reports.group_activities"),
            ],
            "kbi_page_course_progress" => [
                t("moodle_reports.course_progress"), t("moodle_reports.course_progress_desc"), t(
                    "moodle_reports.group_courses"
                ),
            ],
            "kbi_page_course_stats_advanced" => [
                t("moodle_reports.course_stats"), t("moodle_reports.course_stats_desc"), t(
                    "moodle_reports.group_courses"
                ),
            ],
            "kbi_page_course_enrollments_with_comple" => [
                t("moodle_reports.course_enrolments"), t(
                    "moodle_reports.course_enrolments_desc"
                ), t("moodle_reports.group_participants"),
            ],
            "kbi_page_activity_completion_stats" => [
                t("moodle_reports.activity_completion"), t(
                    "moodle_reports.activity_completion_desc"
                ), t("moodle_reports.group_activities"),
            ],
            "kbi_page_activity_status_detail" => [
                t("moodle_reports.activity_status"), t("moodle_reports.activity_status_desc"), t(
                    "moodle_reports.group_activities"
                ),
            ],
            "kbi_page_quiz_attempts_summary_by_course" => [
                t("moodle_reports.quiz_attempts"), t(
                    "moodle_reports.quiz_attempts_desc"
                ), t("moodle_reports.group_activities"),
            ],
            "kbi_page_forum_activity_details" => [
                t("moodle_reports.forum_activity"), t("moodle_reports.forum_activity_desc"), t(
                    "moodle_reports.group_activities"
                ),
            ],
            "kbi_page_scorm_activity_summary_by_course" => [
                t("moodle_reports.scorm_summary"), t(
                    "moodle_reports.scorm_summary_desc"
                ), t("moodle_reports.group_activities"),
            ],
        ];
    }
}
