<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\Pages\ManageUsers;

class ManageStudents extends ManageUsers
{
    protected static string $resource = StudentResource::class;

    public function getSubheading(): ?string
    {
        return 'Bấm vào học viên để mở Học bạ: khoá học & tiến độ, kết quả bài kiểm tra đầu vào, gói thành viên, hoạt động.';
    }
}
