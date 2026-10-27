/**
 * Trình soạn nội dung bài học (Editor.js) — Alpine component chạy bất đồng bộ trong Filament.
 *
 * Build:  node bin/build.js        (ra resources/js/dist/components/lesson-editor.js)
 * Đăng ký: AlpineComponent::make('lesson-editor', …) trong AppServiceProvider, rồi `php artisan filament:assets`.
 *
 * Dữ liệu lưu dạng JSON của Editor.js vào bản nháp (Livewire: saveDraft). Tự lưu sau khi ngừng gõ.
 */
import EditorJS from '@editorjs/editorjs'
import Header from '@editorjs/header'
import EditorjsList from '@editorjs/list'
import Quote from '@editorjs/quote'
import Delimiter from '@editorjs/delimiter'
import ImageTool from '@editorjs/image'
import Table from '@editorjs/table'
import CodeTool from '@editorjs/code'
import InlineCode from '@editorjs/inline-code'
import Marker from '@editorjs/marker'
import Warning from '@editorjs/warning'
import QuizTool from './quiz-tool.js'

/** Bản dịch tiếng Việt cho giao diện Editor.js và các tool. */
const vietnamese = {
    messages: {
        ui: {
            blockTunes: {
                toggler: {
                    'Click to tune': 'Bấm để tùy chỉnh',
                    'or drag to move': 'hoặc kéo để di chuyển',
                },
            },
            inlineToolbar: {
                converter: { 'Convert to': 'Chuyển thành' },
            },
            toolbar: {
                toolbox: { Add: 'Thêm khối' },
            },
            popover: {
                Filter: 'Tìm',
                'Nothing found': 'Không tìm thấy',
                'Convert to': 'Chuyển thành',
            },
        },
        toolNames: {
            Text: 'Đoạn văn',
            Heading: 'Tiêu đề',
            'Unordered List': 'Danh sách chấm',
            'Ordered List': 'Danh sách số',
            Checklist: 'Danh sách kiểm',
            Quote: 'Trích dẫn',
            Delimiter: 'Đường phân cách',
            Image: 'Ảnh',
            Table: 'Bảng',
            Code: 'Mã code',
            Warning: 'Lưu ý',
            Link: 'Liên kết',
            Bold: 'Đậm',
            Italic: 'Nghiêng',
            InlineCode: 'Code trong dòng',
            Marker: 'Tô sáng',
            'Câu hỏi': 'Câu hỏi',
        },
        tools: {
            link: { 'Add a link': 'Dán liên kết' },
            stub: { 'The block can not be displayed correctly.': 'Khối này không hiển thị được.' },
            header: {
                'Heading 1': 'Tiêu đề 1',
                'Heading 2': 'Tiêu đề 2',
                'Heading 3': 'Tiêu đề 3',
                'Heading 4': 'Tiêu đề 4',
            },
            list: {
                Ordered: 'Danh sách số',
                Unordered: 'Danh sách chấm',
                Checklist: 'Danh sách kiểm',
                'Counter type': 'Kiểu đánh số',
                'Start with': 'Bắt đầu từ',
            },
            image: {
                Caption: 'Chú thích ảnh',
                'Select an Image': 'Chọn ảnh',
                'With border': 'Có viền',
                'Stretch image': 'Giãn toàn chiều rộng',
                'With background': 'Có nền',
                'With caption': 'Có chú thích',
                'Couldn’t upload image. Please try another.': 'Không tải được ảnh. Vui lòng thử ảnh khác.',
            },
            table: {
                Heading: 'Hàng tiêu đề',
                'With headings': 'Có hàng tiêu đề',
                'Without headings': 'Không có hàng tiêu đề',
                Stretch: 'Giãn toàn chiều rộng',
                Collapse: 'Thu gọn',
                'Add column to left': 'Thêm cột bên trái',
                'Add column to right': 'Thêm cột bên phải',
                'Delete column': 'Xóa cột',
                'Add row above': 'Thêm hàng phía trên',
                'Add row below': 'Thêm hàng phía dưới',
                'Delete row': 'Xóa hàng',
            },
            quote: {
                'Align Left': 'Căn trái',
                'Align Center': 'Căn giữa',
            },
        },
        blockTunes: {
            delete: { Delete: 'Xóa', 'Click to delete': 'Bấm lần nữa để xóa' },
            moveUp: { 'Move up': 'Lên trên' },
            moveDown: { 'Move down': 'Xuống dưới' },
        },
    },
}

export default function lessonEditor({ initialData, autosaveDelay = 1500 }) {
    let editor = null
    let timer = null

    return {
        /** saved | dirty | saving | error */
        status: 'saved',
        savedAt: null,

        init() {
            // Không dùng x-ref: khung soạn thảo nằm trong <x-filament::section> (có x-data riêng),
            // nên $refs của component này không thấy được nó. Tìm theo thuộc tính data.
            const holder = this.$el.querySelector('[data-lesson-editor-holder]')

            if (! holder) {
                console.error('Không tìm thấy khung soạn thảo [data-lesson-editor-holder].')

                return
            }

            editor = new EditorJS({
                holder,
                data: initialData && Array.isArray(initialData.blocks) ? initialData : { blocks: [] },
                placeholder: 'Bắt đầu viết nội dung bài học… (bấm + hoặc gõ / để chèn khối)',
                inlineToolbar: ['bold', 'italic', 'link', 'marker', 'inlineCode'],
                i18n: vietnamese,
                tools: {
                    header: {
                        class: Header,
                        inlineToolbar: true,
                        config: { levels: [2, 3, 4], defaultLevel: 2, placeholder: 'Tiêu đề' },
                    },
                    list: {
                        class: EditorjsList,
                        inlineToolbar: true,
                        config: { defaultStyle: 'unordered' },
                    },
                    quote: {
                        class: Quote,
                        inlineToolbar: true,
                        config: { quotePlaceholder: 'Nội dung trích dẫn', captionPlaceholder: 'Tác giả / nguồn' },
                    },
                    delimiter: Delimiter,
                    image: {
                        class: ImageTool,
                        config: {
                            captionPlaceholder: 'Chú thích ảnh',
                            buttonContent: 'Chọn ảnh để tải lên',
                            types: 'image/png, image/jpeg, image/webp, image/gif',
                            uploader: {
                                uploadByFile: (file) => this.uploadImage(file),
                                // Không lấy ảnh từ link ngoài: ảnh phải nằm trên server để app dùng được offline
                                uploadByUrl: () => Promise.resolve({ success: 0 }),
                            },
                        },
                    },
                    table: {
                        class: Table,
                        inlineToolbar: true,
                        config: { rows: 2, cols: 2, withHeadings: true },
                    },
                    code: {
                        class: CodeTool,
                        config: { placeholder: 'Nhập code' },
                    },
                    warning: {
                        class: Warning,
                        inlineToolbar: true,
                        config: { titlePlaceholder: 'Tiêu đề lưu ý', messagePlaceholder: 'Nội dung lưu ý' },
                    },
                    quiz: QuizTool,
                    inlineCode: InlineCode,
                    marker: Marker,
                },
                onChange: () => this.queueSave(),
            })

            // Cảnh báo khi rời trang mà còn thay đổi chưa lưu
            this._beforeUnload = (event) => {
                if (this.status === 'dirty' || this.status === 'saving') {
                    event.preventDefault()
                    event.returnValue = ''
                }
            }
            window.addEventListener('beforeunload', this._beforeUnload)
        },

        destroy() {
            clearTimeout(timer)
            window.removeEventListener('beforeunload', this._beforeUnload)
            editor?.destroy?.()
            editor = null
        },

        queueSave() {
            this.status = 'dirty'
            clearTimeout(timer)
            timer = setTimeout(() => this.save(), autosaveDelay)
        },

        /** Lưu bản nháp ngay (dùng cho nút "Lưu nháp" và trước khi xuất bản). */
        async save() {
            clearTimeout(timer)

            if (! editor) {
                return
            }

            this.status = 'saving'

            try {
                const data = await editor.save()
                await this.$wire.saveDraft(data)
                this.status = 'saved'
                this.savedAt = new Date()
            } catch (error) {
                console.error(error)
                this.status = 'error'
            }
        },

        /** Lưu xong mới mở hộp thoại xuất bản, để bản xuất bản là nội dung mới nhất. */
        async saveAndPublish() {
            await this.save()

            if (this.status === 'saved') {
                this.$wire.mountAction('publish')
            }
        },

        /**
         * Tải ảnh qua cơ chế upload của Livewire (không cần route riêng / CSRF):
         * file → thuộc tính tạm `editorImage` → server lưu vào public disk, trả URL.
         */
        uploadImage(file) {
            return new Promise((resolve) => {
                this.$wire.upload(
                    'editorImage',
                    file,
                    async () => {
                        try {
                            const url = await this.$wire.storeEditorImage()
                            resolve(url ? { success: 1, file: { url } } : { success: 0 })
                        } catch (error) {
                            console.error(error)
                            resolve({ success: 0 })
                        }
                    },
                    () => resolve({ success: 0 }),
                )
            })
        },

        statusLabel() {
            switch (this.status) {
                case 'dirty':
                    return 'Có thay đổi chưa lưu…'
                case 'saving':
                    return 'Đang lưu…'
                case 'error':
                    return 'Lưu thất bại — bấm Lưu nháp để thử lại'
                default:
                    return this.savedAt
                        ? 'Đã lưu nháp lúc ' + this.savedAt.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
                        : 'Bản nháp đã được lưu'
            }
        },
    }
}