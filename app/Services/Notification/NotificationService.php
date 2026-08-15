<?php

namespace App\Services\Notification;

use App\Enums\NotificationType;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Course;
use App\Models\User;
use App\Notifications\EduSparkNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
     public function notifyNewChatMessage(ChatRoom $room, ChatMessage $message, User $recipient): void
    {
        $recipient->notify(new EduSparkNotification(
            NotificationType::NewChatMessage,
            [
                'room_id' => $room->id,
                'message_id' => $message->id,
               'sender_name' => match (true) {
                        $message->sender_type === \App\Enums\SenderType::STUDENT => $room->student->name,
                        $message->sender_type === \App\Enums\SenderType::TEACHER => $room->teacher?->name,
                        default => 'Système'},
                'preview' => Str::limit($message->content, 100),
            ]
        ));
    }

    public function notifySwitchToHuman(ChatRoom $room): void
{
    if ($room->teacher) {
        $room->teacher->notify(new EduSparkNotification(
            NotificationType::SwitchToHuman,
            [
                'room_id' => $room->id,
                'student_name' => $room->student->name,
                'course_title' => $room->course?->title,
            ]
        ));
        return;
    }

    $teachers = $room->course?->teachers ?? collect();

    if ($teachers->isNotEmpty()) {
        \Illuminate\Support\Facades\Notification::send(
            $teachers,
            new EduSparkNotification(
                NotificationType::SwitchToHuman,
                [
                    'room_id' => $room->id,
                    'student_name' => $room->student->name,
                    'course_title' => $room->course?->title,
                ]
            )
        );
    }
}

     public function notifySwitchToAi(ChatRoom $room): void
    {
        $room->student->notify(new EduSparkNotification(
            NotificationType::SwitchToAi,
            [
                'room_id' => $room->id,
                'course_title' => $room->course?->title,
            ]
        ));
    }

    public function notifyNewLesson(Course $course, string $lessonTitle): void
    {
        $this->notifyMany(
            $course->enrolledStudents()->get(), // adapte selon ta relation
            NotificationType::NewLessonPublished,
            [
                'course_id' => $course->id,
                'course_title' => $course->title,
                'lesson_title' => $lessonTitle,
            ]
        );
    }

    public function notifyAssignmentGraded(User $student, string $assignmentTitle, float $grade): void
    {
        $student->notify(new EduSparkNotification(
            NotificationType::AssignmentGraded,
            [
                'assignment_title' => $assignmentTitle,
                'grade' => $grade,
            ]
        ));
    }

    public function notifyNewStudentEnrolled(User $teacher, User $student, Course $course): void
    {
        $teacher->notify(new EduSparkNotification(
            NotificationType::NewStudentEnrolled,
            [
                'course_id' => $course->id,
                'course_title' => $course->title,
                'student_name' => $student->name,
            ]
        ));
    }

    /**
     * Notifier plusieurs utilisateurs d'un coup (ex: tous les élèves d'un cours).
     * Utilise notifyNow ou notify selon si ShouldQueue est actif.
     */
    private function notifyMany(Collection $recipients, NotificationType $type, array $payload): void
    {
        \Illuminate\Support\Facades\Notification::send(
            $recipients,
            new EduSparkNotification($type, $payload)
        );
    }
}
