<?php
class CalendarController
{
    /** 独立日历页（/calendar）：管理员会话；自包含视图，$calendarBootstrap 携带 CSRF。 */
    public function page(): void
    {
        Auth::requirePageSession();
        header('Cache-Control: no-store');
        $calendarBootstrap = [
            'csrf' => Auth::csrfToken(),
            'user' => (string) Auth::user(),
        ];
        require PUBLIC_DIR . '/views/calendar.php';
    }
    public function index(): void {
        Auth::requireSession();
        json_out((new CalendarService())->listing((string) ($_GET['month'] ?? date('Y-m'))));
    }
    public function createTask(): void { Auth::requireSession(); json_out((new CalendarService())->saveTask(body_json())); }
    public function updateTask(string $id): void { Auth::requireSession(); json_out((new CalendarService())->saveTask(body_json(), $id)); }
    public function deleteTask(string $id): void { Auth::requireSession(); json_out((new CalendarService())->deleteTask($id)); }
    public function addRun(): void { Auth::requireSession(); json_out((new CalendarService())->addRun(body_json())); }
    public function saveCheckin(string $date): void { Auth::requireSession(); json_out((new CalendarService())->saveCheckin($date, body_json())); }
    public function deleteCheckin(string $date): void { Auth::requireSession(); json_out((new CalendarService())->deleteCheckin($date)); }
}
