<?php

namespace App\Services;

use App\Enums\RegistrationSource;
use App\Enums\TrainingCourseStatus;
use App\Exceptions\RegistrationRefused;
use App\Models\TrainingCourse;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use Illuminate\Support\Facades\DB;

/**
 * Đăng ký chương trình huấn luyện (cả chương trình) và workshop (từng buổi).
 *
 * Quy tắc chung:
 *  - Luôn cần gói thành viên còn hạn (cả khi admin thêm tay — cấp gói ở Học bạ trước).
 *  - price > 0: học viên tự đăng ký trên app → payment_required (chờ cổng thanh toán); admin thêm tay được
 *    (VD: học viên đã chuyển khoản).
 *  - Số người tối đa: chương trình → cả chương trình; workshop → mỗi buổi (null = không giới hạn). Áp dụng cả khi admin thêm.
 *  - App: chỉ đăng ký khi còn mở (chương trình: buổi 1 chưa bắt đầu; workshop: buổi chưa bắt đầu, chưa huỷ).
 *  - Đã đăng ký rồi → trả lại đăng ký cũ (không lỗi).
 *  - Huỷ trên app: trước khi bắt đầu, và chỉ đăng ký tự làm trên app (đăng ký admin thêm — VD đã thu tiền — liên hệ admin).
 */
class EventRegistration
{
    public function registerTraining(User $user, TrainingCourse $course, RegistrationSource $source, ?User $actor = null, ?string $note = null): TrainingRegistration
    {
        return DB::transaction(function () use ($user, $course, $source, $actor, $note) {
            TrainingCourse::query()->whereKey($course->getKey())->lockForUpdate()->first();

            if ($existing = $course->registrations()->where('user_id', $user->id)->first()) {
                return $existing;
            }

            $this->checkCommon($user, $course->price, $source, $source === RegistrationSource::App && ! $course->isRegistrationOpen());

            if ($course->registrations()->count() >= $course->capacity) {
                throw RegistrationRefused::full();
            }

            return $course->registrations()->create([
                'user_id' => $user->id,
                'source' => $source,
                'note' => $note,
                'created_by_id' => $actor?->id,
            ]);
        });
    }

    public function cancelTraining(User $user, TrainingCourse $course): void
    {
        $registration = $course->registrations()->where('user_id', $user->id)->first();
        if (! $registration) {
            return;
        }
        $first = $course->firstSession();
        if ($first && ! $first->starts_at->isFuture()) {
            throw new RegistrationRefused('cancel_closed', 'Chương trình đã bắt đầu, không huỷ đăng ký được nữa.');
        }
        if ($registration->source !== RegistrationSource::App) {
            throw new RegistrationRefused('cancel_not_allowed', 'Đăng ký này do Ellso thêm cho bạn — vui lòng liên hệ Ellso để huỷ.');
        }

        $registration->delete();
    }

    public function registerWorkshop(User $user, WorkshopSession $session, RegistrationSource $source, ?User $actor = null, ?string $note = null): WorkshopRegistration
    {
        return DB::transaction(function () use ($user, $session, $source, $actor, $note) {
            WorkshopSession::query()->whereKey($session->getKey())->lockForUpdate()->first();
            /** @var Workshop $workshop */
            $workshop = $session->workshop;

            if ($existing = $session->registrations()->where('user_id', $user->id)->first()) {
                return $existing;
            }

            $closed = $source === RegistrationSource::App
                && ($workshop->status !== TrainingCourseStatus::Published || $workshop->trashed() || ! $session->isRegistrationOpen());
            $this->checkCommon($user, $workshop->price, $source, $closed);

            if ($workshop->hasCapacity() && $session->registrations()->count() >= $workshop->capacity) {
                throw RegistrationRefused::full();
            }

            return $session->registrations()->create([
                'user_id' => $user->id,
                'source' => $source,
                'note' => $note,
                'created_by_id' => $actor?->id,
            ]);
        });
    }

    public function cancelWorkshop(User $user, WorkshopSession $session): void
    {
        $registration = $session->registrations()->where('user_id', $user->id)->first();
        if (! $registration) {
            return;
        }
        if (! $session->starts_at->isFuture()) {
            throw new RegistrationRefused('cancel_closed', 'Buổi workshop đã bắt đầu, không huỷ đăng ký được nữa.');
        }
        if ($registration->source !== RegistrationSource::App) {
            throw new RegistrationRefused('cancel_not_allowed', 'Đăng ký này do Ellso thêm cho bạn — vui lòng liên hệ Ellso để huỷ.');
        }

        $registration->delete();
    }

    private function checkCommon(User $user, int $price, RegistrationSource $source, bool $closed): void
    {
        if ($closed) {
            throw RegistrationRefused::closed();
        }
        if (! $user->hasActiveMembership()) {
            throw RegistrationRefused::membershipRequired();
        }
        if ($price > 0 && $source === RegistrationSource::App) {
            throw RegistrationRefused::paymentRequired($price);
        }
    }
}
