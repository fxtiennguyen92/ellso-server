<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

/** Ngân hàng đề thi: admin quản lý. Đề đang dùng trong lộ trình hoặc đã có bài làm thì không xoá được. */
class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Exam $exam): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, Exam $exam): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $user->isAdmin() && $exam->pathChapters()->doesntExist() && $exam->attempts()->doesntExist();
    }
}
