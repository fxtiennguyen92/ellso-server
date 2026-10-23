<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;

/**
 * Admin: toàn quyền. Giảng viên: chỉ khóa học của mình,
 * không tự xuất bản/ẩn, không xóa khóa đã xuất bản.
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, Course $course): bool
    {
        return $user->isAdmin() || $this->owns($user, $course);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function update(User $user, Course $course): bool
    {
        return $user->isAdmin() || $this->owns($user, $course);
    }

    public function delete(User $user, Course $course): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->owns($user, $course) && $course->status !== CourseStatus::Published;
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Duyệt / xuất bản / ẩn khóa học */
    public function publish(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    private function owns(User $user, Course $course): bool
    {
        return $user->isInstructor() && $course->instructor_id === $user->id;
    }
}
