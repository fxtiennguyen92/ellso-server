<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\Pages\ManageUserLogs;

class ManageStudentLogs extends ManageUserLogs
{
    protected static string $resource = StudentResource::class;
}
