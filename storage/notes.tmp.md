# Ellso – ghi chú nghiệp vụ & API phía server

> Bổ sung cho `elearning-spec (1).md`. Ghi lại các quy tắc đã chốt khi làm repo `ellso-server` (nhánh `claude`).
> Cập nhật: 26/10/2026.

---

## 1. Gói thành viên (= gói học viên, đã chốt 25/10/2026)

- Mỗi **học viên** cần **gói thành viên** để **xem các tài liệu** và **tham gia các khoá huấn luyện**.
- Gói **luôn 1 năm** (cố định trong code: `MembershipService::MONTHS = 12`). Đang có gói còn hạn mà mua / được tặng thêm → gói mới **nối tiếp** ngay sau ngày hết hạn.
- Trong app đây là quyền **plus** của module Tài liệu, màn **Quản lý gói** `/profile/quan-ly-goi`. `GET /v1/me` trả `membership: { active, ends_at }`.

### 4 nguồn có gói (`student_memberships.source`)
| Nguồn | Khi nào | Thiết lập trên CMS |
|---|---|---|
| `purchase` – Mua gói | Học viên mua gói (giá cấu hình) | Marketing › **Gói thành viên** › Giá gói 1 năm (`student_membership_price`; trống = chưa bán) |
| `campaign` – Chiến dịch tặng gói | Tài khoản học viên **được tạo trong thời gian chiến dịch đang bật** (tự đăng ký hoặc được admin mời) → tặng ngay 1 năm, miễn phí. Mỗi chiến dịch tặng 1 lần / người | Marketing › **Chiến dịch tặng gói** (tên, bắt đầu, kết thúc, bật/tạm dừng). Nút **"Tặng cho người đã đăng ký"**: tặng bù cho học viên đăng ký trong thời gian chiến dịch nhưng chưa nhận (tính từ hôm nay) |
| `bundle` – Tặng kèm sản phẩm | Mua khoá học (sau này: tài liệu) nằm trong danh sách "mua kèm được tặng gói" | Marketing › Gói thành viên › **Mua kèm được tặng gói** (bảng `membership_bundle_items`, `item_type` = course; thêm `document` khi có module tài liệu) |
| `admin` – Admin cấp | Admin cấp / gia hạn tay (VD học viên chuyển khoản) | Hệ thống › Người dùng › ⋮ **Cấp / Gia hạn gói thành viên** (số tiền đã trả, ghi chú) |

- **Chưa làm:** mua gói và mua khoá học qua thanh toán (chờ chốt cổng thanh toán). Khi có đơn hàng: thanh toán thành công gọi `MembershipService::grant(..., Purchase, giá)` khi mua gói, và `grantForPurchase(user, sản phẩm, order_id)` khi mua sản phẩm (tự kiểm tra có kèm gói không).
- Mỗi lần nhận gói ghi nhật ký người dùng: **Nhận gói thành viên**.

---

## 2. Lượt mở app (tracking cho Thống kê › Hoạt động học viên)

### Định nghĩa (đã chốt 25/10/2026)
- **1 lượt mở app = 1 phiên.** Mở app, hoặc quay lại app sau **≥ 30 phút** không có tín hiệu → phiên mới.
- Tín hiệu trong vòng 30 phút chỉ **kéo dài** phiên đang có → đo được **thời gian dùng app** mỗi lượt.
- Mỗi thiết bị một phiên riêng (theo `X-Device-Id`).
- Thống kê chỉ tính tài khoản vai trò **học viên**. Mọi mốc theo **giờ Việt Nam**, tuần bắt đầu thứ Hai.

### App cần làm
Gọi `POST /v1/app-sessions/ping` (đã đăng nhập, không cần body):
1. khi mở app (cold start),
2. khi app quay lại foreground,
3. mỗi **60 giây** khi app đang ở foreground (dừng khi app xuống nền).

Header thiết bị gửi kèm **mọi request** (đã dùng cho log đăng nhập):

| Header | Ví dụ |
|---|---|
| `X-Device-Id` | id cố định của máy (bắt buộc nên có — để tách phiên theo thiết bị) |
| `X-Device-Name` | iPhone 15 Pro |
| `X-Device-Platform` | `ios` · `android` · `web` |
| `X-Device-OS` | 18.1 |
| `X-App-Version` | 1.0.3 |

Phản hồi: `{ session_id, new_session, heartbeat_seconds: 60, idle_timeout_seconds: 1800 }` (201 khi tạo phiên mới, 200 khi kéo dài). Giới hạn 30 lần/phút/người.

Dữ liệu: bảng `app_sessions` (`started_at` = lúc mở, `last_seen_at` = tín hiệu cuối, thiết bị, nền tảng, phiên bản app).

### Đã làm trong ellso-app (25/10/2026, nhánh `claude`)
- `src/auth/useAppSessionTracking.ts` — gắn ở `src/app/_layout.tsx › AppShell` (`useAppSessionTracking(token)`), chỉ chạy khi đã đăng nhập (token đầy đủ).
  Ping ngay khi vào app / khi quay lại foreground (web: tab hiện lại), heartbeat theo `heartbeat_seconds` của server, dừng khi xuống nền hoặc đăng xuất; lỗi bỏ qua im lặng.
- `src/data/appSession.ts › apiPingAppSession(token)`. Header `X-Device-*` có sẵn từ `src/lib/device.ts`.
- Đã thử bản web với server local: đăng nhập → 1 phiên; ẩn/hiện tab → ping lại; mỗi 60 giây → ping (phiên ghi `ping_count` 3, ~70 giây).

---

## 3. Màn Thống kê trên CMS

Nhóm menu **Thống kê** (giữa Marketing và Hệ thống). Chỉ admin xem.

### 3.1 Hoạt động học viên
- **3 thẻ toàn thời gian / hiện tại** (không theo bộ lọc):
  - Tổng học viên.
  - Tỉ lệ có gói học viên = học viên **đang có gói còn hạn** / tổng học viên (mô tả kèm số đang còn hạn và số từng mua).
  - Nền tảng: tỉ lệ % lượt mở app theo iOS / Android / Web.
- **Bộ lọc:** từ ngày – đến ngày, hiển thị theo ngày / tuần / tháng, nút **Hiển thị** (chỉ áp dụng khi bấm).
- **Biểu đồ lớn:** cột = lượt mở app (trục trái, có lưới ngang), đường = học viên đăng ký mới (trục phải; tài khoản học viên được tạo, gồm tự đăng ký và được admin mời); mô tả kèm tổng lượt, số học viên, thời gian trung bình mỗi lượt, số học viên mới.
- **3 biểu đồ nhỏ:** tỉ lệ nền tảng (vòng), lượt mở theo thứ trong tuần (T2–CN), theo giờ trong ngày (0h–23h).
- Màu nền tảng cố định: iOS `#047857` · Android `#d97706` · Web `#6366f1` · Khác `#94a3b8` (đã kiểm tra phân biệt được với người mù màu, cả nền sáng lẫn tối).

### 3.2 Mã khuyến mãi
- Bộ lọc: một / nhiều mã (gõ để tìm), từ ngày – đến ngày, ngày / tuần / tháng, nút Hiển thị.
- 4 thẻ: khách dùng mã, đơn dùng mã (2 thẻ hẹp) · tổng tiền đã giảm, doanh thu từ đơn có mã (2 thẻ rộng). Tiền viết tắt kiểu VN (500k, 9,81tr, 1,25 tỷ), rê chuột xem số đầy đủ.
- 1 biểu đồ hai trục: cột = số lần sử dụng (trục trái, có lưới ngang), đường = doanh thu (trục phải). Tooltip hiện số tiền đầy đủ.

### Dữ liệu demo (chỉ local)
```
php artisan db:seed --class=DemoPromoCodeSeeder
php artisan db:seed --class=DemoStudentActivitySeeder
php artisan db:seed --class=DemoPlacementTestSeeder   # bài kiểm tra đầu vào mẫu + bài làm demo
```

---

## 4. Chiến dịch marketing (banner Home + trang chi tiết)
CMS: Marketing › Chiến dịch (chỉ admin). Bảng `marketing_campaigns`.

- **Banner cố định 1280 × 720 px (tỉ lệ 16:9)**; thẻ banner trên Home của app hiển thị theo đúng tỉ lệ 16:9 (rộng = màn hình − 32, bo góc 24). CMS có công cụ cắt ảnh về 16:9 và tự resize về 1280×720; ảnh sai tỉ lệ bị từ chối. Chữ/logo trong banner nên cách mép ≥ 60 px.
- **Thời gian hiển thị** `starts_at`–`ends_at` (giờ VN) + công tắc `is_active`. Trạng thái: Đang ẩn / Sắp hiển thị / Đang hiển thị / Đã kết thúc.
- **Nhiều chiến dịch cùng lúc**: app hiện dạng trượt theo `sort_order` (kéo thả trong danh sách CMS). Chiến dịch mới xếp cuối.
- **Nội dung chi tiết** = danh sách khối: `paragraph`, `heading`, `steps` (đánh số), `bullets`, `promo_code` (liên kết mã khuyến mãi có sẵn → app hiện ô mã + nút Sao chép), `image`, `callout` (info | warning). Text thuần, `**đậm**`, xuống dòng = tách đoạn.
- **Nút cuối trang** (`cta`): không có / mở khoá học / mở liên kết.
- Nút "Xem trước trên app" trong form hiển thị 2 màn điện thoại (Home + chi tiết) từ dữ liệu chưa lưu — đây cũng là spec giao diện cho app.
- Nhân bản chiến dịch → bản sao ở trạng thái ẩn.

API (public, không cần token):
- `GET /v1/campaigns` → `data: [{id, title, summary, banner{url,width,height}, starts_at, ends_at}]` (tối đa 10, chỉ đang hiển thị, đúng thứ tự).
- `GET /v1/campaigns/{id}` → thêm `blocks` (đã chuẩn hoá, ảnh là URL đầy đủ, promo_code có `code, description, discount, ends_at, note`) và `cta` (`{type:'url',label,url}` | `{type:'course',label,course_id}` | null). Chiến dịch ẩn/hết hạn → 404.

Lưu ý môi trường: cần `php artisan storage:link`. Link ảnh tự theo domain + cổng đang gọi (xem mục Ghi chú kỹ thuật), không phụ thuộc APP_URL.

---

## 5. Bài kiểm tra đầu vào (chốt 26/10/2026)
CMS: Đào tạo › Bài kiểm tra đầu vào (chỉ admin). Một bài chung, học viên làm sau khi đăng ký (có thể làm lại). Chỉ **một bài đang dùng**; bật bài khác thì bài cũ tự tắt.

**Cấu trúc** (tab trong mỗi bài): Câu hỏi · Năng lực & mức độ · Bài làm · Thông tin.
- **Năng lực** (VD: Khả năng tiếp thu; trong code là "dimension") có nhiều **mức độ**. Mỗi mức có: tên, *điểm từ* (ngưỡng), nhận xét cho học viên, các khoá học gợi ý.
- **Câu hỏi**: `single` (chọn 1), `multiple` (chọn nhiều, tuỳ chọn min/max số đáp án), `ordering` (sắp xếp tất cả các mục).
- **Đáp án** có điểm cho từng phân loại (`scores = {dimension_id: điểm}`, cho phép âm, trống = 0).

**Chấm điểm tự động** (`App\Services\PlacementScoring`), cách "cộng điểm + ngưỡng":
1. Chọn 1 / chọn nhiều: cộng điểm của mọi đáp án được chọn vào từng phân loại.
2. Sắp xếp: điểm của mục × trọng số vị trí học viên xếp nó. Trọng số mặc định n−1, …, 1, 0 (hạng cuối = 0), admin sửa được.
3. Mức đạt = mức có *điểm từ* cao nhất mà tổng điểm ≥ ngưỡng; thấp hơn mọi mức → mức thấp nhất.
- CMS tự tính **khoảng điểm có thể đạt** của mỗi phân loại để đặt ngưỡng; mức vượt điểm tối đa bị đánh dấu "không thể đạt".
- Nút **Làm thử**: admin trả lời như học viên, xem điểm trên thanh ngưỡng + mức + khoá gợi ý (không lưu).
- Bài làm lưu **bản chụp** câu trả lời + kết quả → sửa đề sau này không đổi kết quả cũ. Muốn đổi đề lớn: **Nhân bản** rồi sửa bản sao, bật khi xong. Bài đã có bài làm thì không xoá được.

API (cần đăng nhập, token `app`):
- `GET /v1/placement-test` → `data: null` nếu chưa có bài đang dùng; ngược lại `{id, title, intro, questions:[{id, type, prompt, hint, min_choices?, max_choices?, options:[{id,label}]}], completed, last_completed_at}`. Không trả điểm của đáp án.
- `POST /v1/placement-test/attempts` body `{test_id, answers:[{question_id, option_ids:[…]}]}`. Câu sắp xếp: `option_ids` theo thứ tự học viên xếp (đầu = hạng 1). Phải trả lời mọi câu. 201 → kết quả; 422 lỗi theo `answers.{question_id}`; 409 `code: test_changed` nếu admin đã đổi bài (app tải lại đề).
- `GET /v1/placement-test/result` → kết quả gần nhất hoặc `null`.
- Kết quả: `{id, test_id, completed_at, dimensions:[{id, name, description, score, min_score, max_score, level:{id, name, description, rank (1 = cao nhất), level_count}}], recommended_courses:[{id, title, short_description, cover_url}]}` (khoá đã xuất bản, gợi ý theo cấu hình hiện tại của các mức đạt được).

Menu **Đào tạo › Bài làm đầu vào** (ngay dưới Bài kiểm tra đầu vào): bài làm của mọi bài, mới nhất trước, lọc theo bài / chỉ bài làm mới.
- **Số đỏ** trên menu (và trên tab "Bài làm" của từng bài) = số bài làm mới mà admin đang đăng nhập chưa xem; dòng mới có chấm đỏ. Mở danh sách hoặc tab là tính đã xem. Mốc đã xem lưu riêng cho từng người trong bảng `user_seen_markers` (`App\Support\SeenMarker`, dùng lại được cho các danh sách khác).

### Năng lực = phân loại lộ trình lấy từ bài kiểm tra đầu vào (chốt 26/10/2026)
- Phân loại lộ trình có 2 loại: **Câu hỏi** (như trước, app hỏi học viên) và **Năng lực**.
- Mỗi phân loại của bài kiểm tra đầu vào (VD: Khả năng tiếp thu) **tự động** thành một phân loại Năng lực dùng chung (`classifications.type = ability`). Giá trị = các mức độ, xếp từ cao xuống thấp. Tên / mức sửa ở Bài kiểm tra đầu vào; màn Phân loại lộ trình chỉ xem.
- Khoá học chọn "Năng lực · Khả năng tiếp thu" trong mục Phân loại lộ trình như mọi phân loại khác. Lộ trình đặt điều kiện mức (VD: Xuất sắc, Tốt); để trống = mọi mức.
- **App không hỏi** câu hỏi cho phân loại Năng lực. Giá trị của học viên = mức trong **bài làm mới nhất**. `Course::suggestPathFor($user, $answeredOptionIds)` tự gộp giá trị năng lực rồi chạy thuật toán gợi ý như cũ.
- **Chưa làm bài** → lộ trình có điều kiện năng lực không khớp. Học viên vào lộ trình không đặt năng lực, hoặc lộ trình mặc định.
- Nối bằng **key ổn định** của phân loại / mức (`placement_dimensions.key`, `placement_levels.key`). **Nhân bản** bài kiểm tra giữ nguyên key → điều kiện lộ trình vẫn đúng khi bật bản sao.
  - Bài tạo mới từ đầu có key mới; năng lực của bài cũ chuyển "không dùng".
  - Mức bị xoá chỉ ẩn khỏi danh sách giá trị, không xoá điều kiện cũ.
- Khoá học có tuỳ chọn **"Bắt buộc làm bài kiểm tra đầu vào trước khi học"** (`courses.requires_placement_test`). Bật → học viên chưa có bài làm nào phải làm trước khi bắt đầu bài học đầu tiên (`Course::needsPlacementTest($user)`). Tắt → không cần làm.
- Khi làm API khoá học / bắt đầu học cho app:
  - Trả `requires_placement_test` + `needs_placement_test` cho học viên.
  - Câu hỏi phân loại chỉ gồm loại `question`.
  - Gợi ý lộ trình dùng `suggestPathFor`.

Thống kê › Kiểm tra đầu vào: lọc theo bài + khoảng ngày. Gồm lượt làm bài, học viên đã làm, tỉ lệ học viên mới đã làm bài, biểu đồ lượt làm theo mốc, phân bố mức độ của từng phân loại (mỗi học viên lấy bài làm mới nhất trong khoảng).

---

## 6. Khoá huấn luyện offline (chốt 26/10/2026)
CMS: **Đào tạo › Khoá huấn luyện** và **Thiết lập › Địa điểm huấn luyện** (chỉ admin).

- **Một khoá = một buổi**, mở vào **nhiều ngày** (lịch tổ chức). **Giá theo khoá** (VND / người), ngày nào cũng như nhau.
- Mỗi ngày có ngày, giờ bắt đầu – kết thúc (giờ VN), **địa điểm** riêng, **số người tối đa** riêng, ghi chú. Có thể **huỷ** một ngày: ngày đó ẩn khỏi app nhưng vẫn giữ trong lịch.
  - VD: Quản lý 200.000 ₫ — 15/11 ở Cafe A (tối đa 10), 20/11 ở Cafe B (tối đa 20).
- **Sổ địa chỉ** (`training_locations`): nhãn dễ nhớ + địa chỉ + link bản đồ + ghi chú. Khi thêm ngày, chọn địa điểm đã lưu, hoặc bấm + để lưu địa điểm mới rồi dùng lại cho các ngày sau. Địa điểm đã dùng thì không xoá được.
- Thêm ngày mới tự lấy sẵn giờ / địa điểm / số người của ngày gần nhất. Nút **"Thêm ngày tương tự"** chỉ cần chọn ngày.
- Thông tin khoá: tên, mô tả ngắn, giá, banner **16:9 (1280 × 720)**, trạng thái Nháp / Đang mở / Đã ẩn.
- **Người hướng dẫn = tài khoản Giảng viên** (`training_courses.trainer_id`). Giảng viên đăng nhập CMS thấy **Khoá huấn luyện** chỉ gồm khoá mình hướng dẫn, xem lịch tổ chức (chỉ xem, không sửa). Sau này: quản lý người đăng ký của khoá mình. Admin quản lý toàn bộ.
- **Nội dung chi tiết** dùng chung khối với Chiến dịch (`App\Filament\Support\ContentBlocks`), bỏ khối mã khuyến mãi: đoạn văn, tiêu đề, các bước, danh sách, ảnh, khung lưu ý.

API (public):
- `GET /v1/trainings` → khoá **Đang mở** còn ít nhất một ngày sắp diễn ra, ngày gần nhất trước: `{id, title, summary, trainer{id,name}, price, banner{url,width,height}, next_session, upcoming_sessions_count}`.
- `GET /v1/trainings/{id}` → thêm `blocks` (như Chiến dịch) và `sessions` (sắp diễn ra, chưa huỷ): `{id, starts_at, ends_at, capacity, note, location{label, address, map_url, note}}`. Khoá không mở → 404.
- **Chưa làm** (cùng đợt mua khoá học): đăng ký / thanh toán theo ngày, số chỗ còn lại.

Dữ liệu demo: `php artisan db:seed --class=DemoTrainingSeeder`.

---

## 7. Học bài, câu hỏi trắc nghiệm, tiến độ (chốt 26/10/2026)
**Câu hỏi trong bài**: trình soạn nội dung (Editor.js) có khối **"Câu hỏi"** (`quiz`): chọn 1 / chọn nhiều, đánh dấu đáp án đúng, giải thích (hiện sau khi trả lời). Tối đa 8 đáp án.
- Xuất bản bị chặn nếu câu hỏi chưa hoàn chỉnh: thiếu nội dung, < 2 đáp án, không có đáp án đúng, "Chọn 1" mà nhiều đáp án đúng.
- Code: `App\Support\LessonContent` (`cleanQuiz`, `quizProblems`, `quizzes`, `forApp`), plugin `resources/js/components/quiz-tool.js`. Build lại bằng `node bin/build.js` rồi `php artisan filament:assets`.

**Hoàn thành bài** = đọc đến cuối + trả lời đúng **mọi** câu hỏi (mỗi câu đúng ít nhất 1 lần; sai làm lại không giới hạn). Bài không có câu hỏi: đọc hết là xong.
- **Tiến độ lưu theo bài** (`lesson_progress`), không theo lộ trình.
- **% khoá** = bài hoàn thành / tổng bài **đã xuất bản** của lộ trình đang học.
- **Khoá bài**: "Mở khi xong bài trước" khoá đến khi bài liền trước hoàn thành; "Luôn mở" luôn học được.
- Service: `App\Services\LearningProgress`.

**Ghi danh** (`course_enrollments`):
- Nguồn: Admin mở / Mua khoá / Miễn phí / Mã kích hoạt. Không thời hạn.
- `learning_path_id` null = chưa chọn lộ trình → app hỏi câu hỏi phân loại (loại Câu hỏi), rồi gợi ý bằng `suggestPathFor` (gồm cả Năng lực). Khoá học chung tự gán lộ trình duy nhất.
- Thu hồi khoá: xoá ghi danh, tiến độ bài vẫn giữ.

API (đăng nhập, token `app`):
- `GET /v1/my/courses` → `[{course{id,title,short_description,cover_url,type}, path|null, needs_path, needs_placement_test, progress{total,completed,percent}, next_lesson, enrolled_at, last_activity_at, completed_at}]`.
- `GET /v1/my/courses/{id}`:
  - Chưa có lộ trình → `questions:[{id, question, options:[{id,name,description,selected}]}]`.
  - Có lộ trình → `chapters:[{id,title,color,lessons:[{id,title,icon_url,state: completed|available|locked, completed_at}]}]`.
- `POST /v1/my/courses/{id}/path` `{answers:{classification_id:[option_id…]}}` → gán lộ trình, trả như trên. 409 `placement_test_required` nếu khoá bắt buộc làm bài kiểm tra đầu vào mà chưa làm.
- `GET /v1/lessons/{id}` → `{id,title,summary,icon_url,course_id, content{blocks}, quiz_count, progress{read,completed,quizzes_total,quizzes_correct}}`.
  - Khối `quiz` trên app: `{type, question, options:[{id,text}], answered}` — **không có đáp án đúng / giải thích**.
  - Lỗi: 403 `lesson_locked` / `not_enrolled`, 409 `placement_test_required`. Bài học thử (is_preview) mở được không cần ghi danh.
- `POST /v1/lessons/{id}/read` → đọc đến cuối bài → progress.
- `POST /v1/lessons/{id}/quiz` `{block_id, option_ids[]}` → `{correct, explanation, progress}` (không trả đáp án đúng).

---

## 8. Tài khoản › Học viên + Học bạ (chốt 26/10/2026)
- Nhóm menu mới **Tài khoản** (trên cùng) › **Học viên**: giống Hệ thống › Người dùng (cột, lọc, mời tham gia, gửi lại email, reset mật khẩu, cấp gói, khoá) nhưng chỉ học viên. **Hệ thống › Người dùng** giờ chỉ còn Admin + Giảng viên.
- Bấm vào học viên → **Học bạ** (`/admin/students/{id}`):
  - Thẻ tổng quan: số khoá (đang học / hoàn thành, % TB), bài đã hoàn thành + tỉ lệ trả lời đúng, gói thành viên, lượt mở app 30 ngày.
  - Thông tin tài khoản: kích hoạt, đăng nhập cuối, mã / người giới thiệu, thiết bị gần nhất, câu trả lời phân loại.
  - Năng lực theo bài kiểm tra đầu vào mới nhất.
  - Thao tác: **Mở khoá học** (chọn khoá, tuỳ chọn gán lộ trình, ghi chú), Sửa thông tin, menu Thao tác (gửi lại email, cấp gói, reset mật khẩu, khoá, toàn bộ nhật ký).
  - Tab:
    - **Khoá học**: % hoàn thành, trạng thái, học gần nhất, nguồn; xem tiến độ từng bài + câu hỏi đúng / số lần trả lời; đổi lộ trình; thu hồi.
    - **Bài kiểm tra đầu vào**: các lần làm + xem bài.
    - **Gói thành viên** (+ cấp gói).
    - **Lượt mở app**.
    - **Nhật ký**.
- Dữ liệu demo: `php artisan db:seed --class=DemoLearningSeeder` (câu hỏi mẫu + ghi danh / tiến độ cho học viên demo).

---

## 9. Tài liệu: Video / PDF / Sách (chốt 27/10/2026)
- CMS: Đào tạo › Tài liệu (admin). Thiết lập › Chủ đề tài liệu (chỉ để lọc trên app). Học bạ › tab "Sách đã mua" (admin "Cấp sách" / "Thu hồi").
- Điều kiện xem (đủ MỘT là xem được, phải chọn ít nhất 1): miễn phí · gói thành viên còn hạn · theo khoá học (đã được mở khoá) · theo bài học (bài đang mở theo lộ trình, không tính bài xem trước) · **bán riêng: chỉ Sách** (`price` != null). Logic: `App\Services\DocumentAccess`.
- Tác giả: **Giảng viên Ellso** (`author_id` → users có role giảng viên/admin; tên lấy theo tài khoản; `User::authoredDocuments()` để làm trang hồ sơ giảng viên + tài liệu của họ) hoặc **Tác giả ngoài** (chỉ tên, cột `author`). Chỉ giữ một trong hai. API trả `author` (tên hiển thị) + `teacher` `{id,name,avatar}` | null; lọc `GET /v1/documents?teacher_id=`. `q` tìm cả tên giảng viên.
- Sách: file PDF hoặc EPUB, tuỳ chọn "Cho tải về". PDF: chỉ xem. Bảng `document_purchases` (source purchase | admin).
- File riêng tư trên ổ `DOCUMENTS_DISK` (dev `documents_local` → /private-docs có chữ ký; prod `s3` → presigned URL). Link xem/tải hạn 5 phút.
- Video: upload file gốc → job `ProcessDocumentVideo` (queue) chạy ffmpeg cắt HLS 480p + 720p (không phóng to), mã hoá AES-128, đẩy lên ổ tài liệu `videos/{id}/{random}/`, xoá file gốc. Trạng thái: chờ → đang xử lý → sẵn sàng / lỗi (nút "Xử lý lại video").
  - App gọi `POST /v1/documents/{id}/play` → link master.m3u8 có chữ ký, hạn 5 phút (app gọi lại để lấy link mới). Playlist con + khoá có hạn = thời lượng + 30 phút; mỗi lần lấy khoá đều kiểm tra lại tài khoản còn hoạt động + còn quyền xem (hết gói → khoá không trả nữa).
  - Khoá AES lưu DB (cast encrypted bằng APP_KEY).
- API (đăng nhập): `GET /v1/documents?type=&topic_id=&q=`, `GET /v1/documents/topics`, `GET /v1/documents/{id}`, `POST …/play` (video), `POST …/open` (PDF/sách: url + download_url), `POST|DELETE …/save`, `GET /v1/my/documents` (saved + bought). Khoá → 403 `{code:"locked", access:[…]}`. Format item giống `src/data/documents.ts` của app (access, unlocked, bought, saved, duration…).
- Server cần: ffmpeg + ffprobe (`FFMPEG_BINARY`, `FFPROBE_BINARY`), queue worker (`php artisan queue:work --timeout=3600`), PHP `upload_max_filesize`/`post_max_size` + nginx `client_max_body_size` đủ lớn (`DOCUMENTS_VIDEO_MAX_MB`, mặc định 2048), `composer require league/flysystem-aws-s3-v3` + `AWS_*` khi dùng S3 (S3 bucket để private).
- Migration: `2026_10_27_000002_create_documents_tables.php`, `2026_10_27_000003_add_author_id_to_documents.php`.

## 10. Ghi chú kỹ thuật
- **Xoá ảnh khỏi storage** (trait `App\Models\Concerns\CleansUpFiles`): bấm X ở ô ảnh rồi **Lưu** (hoặc thay ảnh khác) → file cũ bị xoá khỏi storage. Xoá bản ghi → xoá ảnh của nó. Áp dụng cho:
  - Chiến dịch, Khoá huấn luyện: banner + ảnh trong khối nội dung; xoá ngay khi xoá bản ghi.
  - Khoá học (ảnh bìa), Bài học (icon): có thùng rác nên chỉ xoá file khi xoá vĩnh viễn.
  - File còn được bản ghi khác dùng (bản nhân bản) thì giữ lại.
- Link file disk `public` (`/storage/...`) lấy theo **domain + cổng của request** (middleware `UseRequestHostForPublicFiles`), không theo APP_URL. CMS (CMS_DOMAIN) và API (API_DOMAIN) dùng chung thư mục public. Nếu link ảnh sang domain khác thì ô tải ảnh của Filament bị chặn khác origin → kẹt "Loading", không xoá được. Chạy bằng lệnh (queue, mail) vẫn dùng APP_URL.
- Nhóm menu bên trái mặc định đóng; nhóm của trang đang xem luôn mở; nhóm có mục mang số đỏ có chấm đỏ cạnh tên. Xem `resources/views/filament/navigation-groups.blade.php`.
- Các màn thống kê kế thừa `App\Filament\Pages\Statistics\StatisticsPage` (thanh lọc + nút Hiển thị); chia mốc thời gian dùng `App\Support\TimeBuckets`.
- Số liệu hiện gom trong PHP (chạy giống nhau trên MySQL và SQLite). Khi dữ liệu lớn (hàng trăm nghìn phiên / tháng) nên chuyển sang bảng tổng hợp theo ngày, chạy định kỳ.
- Không viết comment `//` trong chuỗi JS tuỳ chọn biểu đồ (Filament gộp thành một dòng → phần sau bị coi là comment).
