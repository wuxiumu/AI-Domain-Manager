<?php
require __DIR__ . '/../app/core/PrivateJsonStore.php';
require __DIR__ . '/../app/core/WorkspaceData.php';
require __DIR__ . '/../app/services/CalendarService.php';
$dir = sys_get_temp_dir() . '/calendar-test-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
function verifyCalendar($v, $message) { if (!$v) throw new RuntimeException($message); }
function rejectCalendar(callable $work) { try { $work(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Expected validation error'); }
try {
    $service = new CalendarService($dir);
    $entry = $service->saveCheckin('2026-10-29', ['mood'=>'good', 'text'=>'今天进展不错'])['checkin'];
    verifyCalendar($entry['mood'] === 'good' && $service->listing('2026-10')['checkins'][0]['text'] === '今天进展不错', 'daily checkin persists');
    $service->saveCheckin('2026-10-29', ['mood'=>'calm', 'text'=>'慢慢来']);
    verifyCalendar(count($service->listing('2026-10')['checkins']) === 1 && $service->listing('2026-10')['checkins'][0]['mood'] === 'calm', 'editing replaces the selected day');
    $service->saveCheckin('2026-11-01', ['mood'=>'great', 'text'=>'新的一天']);
    verifyCalendar(count($service->listing('2026-10')['checkins']) === 1, 'month listing excludes other dates');
    rejectCalendar(fn() => $service->saveCheckin('2026-02-30', ['mood'=>'good', 'text'=>'x']));
    rejectCalendar(fn() => $service->saveCheckin('2026-10-29', ['mood'=>'unknown', 'text'=>'x']));
    rejectCalendar(fn() => $service->saveCheckin('2026-10-29', ['mood'=>'good', 'text'=>'  ']));
    rejectCalendar(fn() => $service->saveCheckin('2026-10-29', ['mood'=>'good', 'text'=>str_repeat('x', 281)]));
    $service->deleteCheckin('2026-10-29');
    verifyCalendar($service->listing('2026-10')['checkins'] === [], 'checkin deletion persists');
    $task = $service->saveTask(['date'=>'2026-10-29','title'=>'检查资产','description'=>'查看证书','done'=>false])['task'];
    verifyCalendar(count($service->listing('2026-10')['tasks']) === 1, 'task persists in month');
    $task['done'] = true; $service->saveTask($task, $task['id']);
    verifyCalendar($service->listing('2026-10')['tasks'][0]['done'], 'task completion persists');
    rejectCalendar(fn() => $service->saveTask(['date'=>'2026-02-30','title'=>'bad']));
    rejectCalendar(fn() => $service->saveTask(['date'=>'2026-10-29','title'=>'   ']));
    rejectCalendar(fn() => $service->addRun(['counts'=>['domain'=>['healthy'=>-1,'unhealthy'=>0]]]));
    $counts = ['domain'=>['healthy'=>2,'unhealthy'=>1], 'ip'=>['healthy'=>3,'unhealthy'=>0], 'subdomain'=>['healthy'=>1,'unhealthy'=>0]];
    $run = $service->addRun(['counts'=>$counts])['run'];
    $service->addRun(['counts'=>$counts]);
    verifyCalendar(count($service->listing(substr($run['date'],0,7))['runs']) === 2, 'every completed run is retained');
    WorkspaceData::write($dir.'/health.json', ['check_events'=>[['checked_at'=>'2026-10-29 12:00:00','kind'=>'domain','name'=>'example.com'], ['checked_at'=>'2026-11-01 12:00:00','kind'=>'ip','name'=>'example']]]);
    verifyCalendar(count($service->listing('2026-10')['events']) === 1, 'calendar includes only selected month of check events');
    $service->deleteTask($task['id']); verifyCalendar(!$service->listing('2026-10')['tasks'], 'task deleted');
    echo "Calendar: checkins, tasks, validation and run history passed\n";
} finally { foreach (glob($dir . '/*') as $file) unlink($file); rmdir($dir); }
