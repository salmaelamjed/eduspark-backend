<?php

namespace App\Enums;

enum NotificationType: string
{
    case NewChatMessage = 'new_chat_message';
    case SwitchToHuman = 'switch_to_human';
    case SwitchToAi = 'switch_to_ai';
    case NewLessonPublished = 'new_lesson_published';
    case AssignmentGraded = 'assignment_graded';
    case AssignmentDeadlineReminder = 'assignment_deadline_reminder';
    case NewStudentEnrolled = 'new_student_enrolled';
     case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case ProfileUpdated = 'profile_updated';
    case NewDeviceLogin = 'new_device_login';


public function label(): string
{
    return match ($this) {
        self::NewChatMessage => 'Nouveau message',
        self::SwitchToHuman => 'Demande de support humain',
        self::SwitchToAi => 'Retour au mode assistant IA',
        self::NewLessonPublished => 'Nouvelle leçon publiée',
        self::AssignmentGraded => 'Devoir noté',
        self::AssignmentDeadlineReminder => 'Rappel de deadline',
        self::NewStudentEnrolled => 'Nouvel élève inscrit',
        self::PasswordChanged => 'Mot de passe modifié',
        self::EmailChanged => 'Adresse email modifiée',
        self::ProfileUpdated => 'Profil mis à jour',
        self::NewDeviceLogin => 'Nouvelle connexion détectée',
    };
}
     public function category(): string
    {
        return match ($this) {
            self::NewChatMessage,
            self::SwitchToHuman,
            self::SwitchToAi => 'chat',

            self::NewLessonPublished,
            self::NewStudentEnrolled => 'course',

            self::AssignmentGraded,
            self::AssignmentDeadlineReminder => 'assignment',

            self::PasswordChanged,
            self::EmailChanged,
            self::ProfileUpdated,
            self::NewDeviceLogin => 'security',
        };
    }
}
