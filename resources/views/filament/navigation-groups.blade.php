{{--
    Nhóm menu bên trái: mặc định đóng hết, nhóm chứa trang đang xem luôn mở.
    Chạy trong <head>, trước script sidebar của Filament (đọc localStorage "collapsedGroups").
    Người dùng mở / đóng nhóm thì trình duyệt nhớ như bình thường.
    ellsoNavDefaults: đổi số này để áp lại mặc định cho mọi trình duyệt đã lưu trạng thái cũ.
--}}
@php
    $active = null;
    $alerts = []; // nhóm có mục mang số đỏ (VD: Bài làm đầu vào) → chấm đỏ cạnh tên nhóm
    foreach (\Filament\Facades\Filament::getNavigation() as $group) {
        foreach ($group->getItems() as $item) {
            if ($active === null && ($item->isActive() || collect($item->getChildItems())->contains(fn ($child) => $child->isActive()))) {
                $active = $group->getLabel();
            }
            if (filled($item->getBadge()) && $item->getBadgeColor() === 'danger') {
                $alerts[] = $group->getLabel();
            }
        }
    }
@endphp
<script>
    (function () {
        try {
            var key = 'collapsedGroups';
            if (localStorage.getItem('ellsoNavDefaults') !== '1') {
                localStorage.removeItem(key);
                localStorage.setItem('ellsoNavDefaults', '1');
            }
            var collapsed = JSON.parse(localStorage.getItem(key));
            if (! Array.isArray(collapsed)) {
                collapsed = @js($groups);
            }
            var active = @js($active);
            localStorage.setItem(key, JSON.stringify(collapsed.filter(function (g) { return g !== active; })));
        } catch (e) {}

        var alerts = @js(array_values(array_unique(array_filter($alerts))));
        if (alerts.length) {
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.fi-sidebar-group').forEach(function (group) {
                    var label = group.querySelector('.fi-sidebar-group-label');
                    if (! label || alerts.indexOf(group.dataset.groupLabel) === -1 || label.querySelector('.ellso-nav-dot')) {
                        return;
                    }
                    var dot = document.createElement('span');
                    dot.className = 'ellso-nav-dot';
                    dot.title = 'Có mục mới';
                    dot.style.cssText = 'display:inline-block;width:7px;height:7px;border-radius:999px;background:var(--danger-500);margin-left:6px;vertical-align:middle';
                    label.appendChild(dot);
                });
            });
        }
    })();
</script>
