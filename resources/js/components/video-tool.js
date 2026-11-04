/**
 * Khối "Video" cho Editor.js — video tải riêng cho bài học (khác video tài liệu).
 *
 * Dữ liệu lưu: { video_id, caption, chapters }
 *  - video_id: LessonVideo của bài (server cắt HLS 480p/720p mã hoá, phát qua link có chữ ký).
 *  - chapters: văn bản, mỗi dòng một mốc "mm:ss Nội dung" (≥ 1 giờ: "h:mm:ss"). App bấm mốc → tua tới đó.
 * Tải lên qua config.upload(file, onProgress) → {id, status, label, duration, name}; trạng thái qua config.status(id).
 * Xuất bản bị chặn khi video chưa xử lý xong / lỗi / mốc sai (App\Support\LessonContent::videoProblems).
 */

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="15" height="14" rx="2"/><path d="M17 10l5-3v10l-5-3z"/></svg>'

const STYLE_ID = 'le-video-style'
const CSS = `
.le-video { border: 1px solid var(--gray-200, #e5e7eb); border-radius: 12px; padding: 14px 16px; margin: 6px 0; background: color-mix(in oklab, var(--info-500, #3b82f6) 5%, transparent); }
.dark .le-video { border-color: var(--gray-700, #374151); }
.le-video__badge { font-size: 12px; font-weight: 600; color: var(--info-700, #1d4ed8); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 10px; }
.dark .le-video__badge { color: var(--info-400, #60a5fa); }
.le-video__drop { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 22px 12px; border: 2px dashed var(--gray-300, #d1d5db); border-radius: 10px; text-align: center; cursor: pointer; font-size: 14px; }
.dark .le-video__drop { border-color: var(--gray-600, #4b5563); }
.le-video__drop:hover { border-color: var(--info-500, #3b82f6); }
.le-video__hint { font-size: 12px; color: var(--gray-500, #6b7280); }
.le-video__file { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-radius: 10px; background: var(--white, #fff); border: 1px solid var(--gray-200, #e5e7eb); font-size: 14px; }
.dark .le-video__file { background: var(--gray-900, #111827); border-color: var(--gray-700, #374151); }
.le-video__name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.le-video__status { font-size: 12px; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.le-video__status.is-ready { background: color-mix(in oklab, var(--success-500, #22c55e) 15%, transparent); color: var(--success-700, #15803d); }
.le-video__status.is-pending, .le-video__status.is-processing { background: color-mix(in oklab, var(--warning-500, #f59e0b) 15%, transparent); color: var(--warning-700, #b45309); }
.le-video__status.is-failed { background: color-mix(in oklab, var(--danger-500, #ef4444) 15%, transparent); color: var(--danger-700, #b91c1c); }
.le-video__bar { height: 6px; border-radius: 999px; background: var(--gray-200, #e5e7eb); overflow: hidden; margin-top: 8px; }
.le-video__bar > div { height: 100%; background: var(--info-500, #3b82f6); transition: width .2s; }
.le-video__replace { font-size: 13px; color: var(--info-700, #1d4ed8); background: transparent; cursor: pointer; padding: 0; }
.le-video__label { font-size: 12px; color: var(--gray-500, #6b7280); margin: 12px 0 6px; }
.le-video textarea, .le-video input[type=text] { width: 100%; border: 1px solid var(--gray-300, #d1d5db); border-radius: 8px; padding: 7px 10px; font-size: 14px; background: var(--white, #fff); color: inherit; resize: vertical; }
.dark .le-video textarea, .dark .le-video input[type=text] { background: var(--gray-900, #111827); border-color: var(--gray-600, #4b5563); }
.le-video textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.6; }
.le-video__error { font-size: 12px; color: var(--danger-600, #dc2626); margin-top: 6px; }
`

const LINE = /^\s*(?:(\d{1,2}):)?(\d{1,3}):(\d{2})\s*(?:[-–—:|]\s*)?(.+?)\s*$/

function formatTime(seconds) {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = seconds % 60
    const pad = (n) => String(n).padStart(2, '0')

    return h ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

/** Kiểm tra nhanh phía trình soạn (server kiểm tra lại khi xuất bản). */
function chapterErrors(text, duration) {
    const errors = []
    const seen = new Set()
    String(text || '').split(/\r?\n/).forEach((line, i) => {
        if (! line.trim()) {
            return
        }
        const m = line.match(LINE)
        if (! m) {
            errors.push(`Dòng ${i + 1}: cần dạng "mm:ss Nội dung".`)

            return
        }
        const [h, min, sec] = [Number(m[1] || 0), Number(m[2]), Number(m[3])]
        if (sec > 59 || (h > 0 && min > 59)) {
            errors.push(`Dòng ${i + 1}: thời gian không hợp lệ.`)

            return
        }
        const start = h * 3600 + min * 60 + sec
        if (duration && start >= duration) {
            errors.push(`Dòng ${i + 1}: vượt quá độ dài video (${formatTime(duration)}).`)
        }
        if (seen.has(start)) {
            errors.push(`Mốc ${formatTime(start)} bị trùng.`)
        }
        seen.add(start)
    })

    return errors
}

export default class VideoTool {
    static get toolbox() {
        return { title: 'Video', icon: ICON }
    }

    static get enableLineBreaks() {
        return true
    }

    constructor({ data, config, readOnly }) {
        this.readOnly = readOnly
        this.config = config || {}
        this.data = {
            video_id: data?.video_id || null,
            caption: data?.caption || '',
            chapters: data?.chapters || '',
        }
        this.state = null // {id, status, label, duration, name, error}
        this.progress = null // % khi đang tải lên
        this.pollTimer = null
        this.wrapper = null

        if (! document.getElementById(STYLE_ID)) {
            const style = document.createElement('style')
            style.id = STYLE_ID
            style.textContent = CSS
            document.head.appendChild(style)
        }
    }

    render() {
        this.wrapper = document.createElement('div')
        this.wrapper.className = 'le-video'
        this.draw()
        if (this.data.video_id) {
            this.refreshState()
        }

        return this.wrapper
    }

    destroy() {
        clearTimeout(this.pollTimer)
    }

    save() {
        return { ...this.data }
    }

    validate() {
        return true // bản nháp luôn lưu được; xuất bản mới kiểm tra
    }

    async refreshState() {
        if (! this.data.video_id || ! this.config.status) {
            return
        }
        try {
            this.state = await this.config.status(this.data.video_id)
        } catch (e) {
            console.error(e)
        }
        this.draw()
        clearTimeout(this.pollTimer)
        if (this.state && (this.state.status === 'pending' || this.state.status === 'processing')) {
            this.pollTimer = setTimeout(() => this.refreshState(), 5000)
        }
    }

    pickFile() {
        const input = document.createElement('input')
        input.type = 'file'
        input.accept = 'video/mp4,video/quicktime,video/x-matroska,video/webm'
        input.onchange = () => input.files[0] && this.upload(input.files[0])
        input.click()
    }

    async upload(file) {
        if (! this.config.upload) {
            return
        }
        this.progress = 0
        this.uploadError = null
        this.draw()
        try {
            const state = await this.config.upload(file, (percent) => {
                this.progress = percent
                const bar = this.wrapper?.querySelector('.le-video__bar > div')
                if (bar) {
                    bar.style.width = percent + '%'
                }
            })
            this.progress = null
            if (! state?.id) {
                throw new Error('upload failed')
            }
            this.data.video_id = state.id
            this.state = state
            this.config.onChange?.()
            this.refreshState()
        } catch (e) {
            console.error(e)
            this.progress = null
            this.uploadError = 'Không tải được video. Kiểm tra định dạng (MP4, MOV, MKV, WebM) và dung lượng rồi thử lại.'
            this.draw()
        }
    }

    draw() {
        const w = this.wrapper
        if (! w) {
            return
        }
        w.innerHTML = ''

        const badge = document.createElement('div')
        badge.className = 'le-video__badge'
        badge.textContent = 'Video bài học'
        w.appendChild(badge)

        if (this.progress !== null) {
            const box = document.createElement('div')
            box.className = 'le-video__file'
            box.style.display = 'block'
            box.innerHTML = '<div>Đang tải video lên…</div><div class="le-video__bar"><div style="width:' + this.progress + '%"></div></div>'
            w.appendChild(box)
        } else if (! this.data.video_id) {
            const drop = document.createElement('div')
            drop.className = 'le-video__drop'
            drop.innerHTML = '<strong>Chọn video để tải lên</strong><span class="le-video__hint">MP4, MOV, MKV hoặc WebM. Sau khi tải lên, hệ thống tự xử lý (vài phút tuỳ độ dài).</span>'
            if (! this.readOnly) {
                drop.onclick = () => this.pickFile()
            }
            w.appendChild(drop)
        } else {
            const s = this.state
            const box = document.createElement('div')
            box.className = 'le-video__file'
            const name = document.createElement('div')
            name.className = 'le-video__name'
            name.textContent = (s?.name || 'Video') + (s?.duration ? ' · ' + formatTime(s.duration) : '')
            const right = document.createElement('div')
            right.style.cssText = 'display:flex;align-items:center;gap:10px;flex:none'
            const status = document.createElement('span')
            status.className = 'le-video__status is-' + (s?.status || 'pending')
            status.textContent = s?.label || 'Đang kiểm tra…'
            right.appendChild(status)
            if (! this.readOnly) {
                const replace = document.createElement('button')
                replace.type = 'button'
                replace.className = 'le-video__replace'
                replace.textContent = 'Thay video'
                replace.onclick = () => this.pickFile()
                right.appendChild(replace)
            }
            box.append(name, right)
            w.appendChild(box)
            if (s?.status === 'failed' && s.error) {
                const err = document.createElement('div')
                err.className = 'le-video__error'
                err.textContent = 'Lỗi xử lý: ' + s.error
                w.appendChild(err)
            }
        }

        if (this.uploadError) {
            const err = document.createElement('div')
            err.className = 'le-video__error'
            err.textContent = this.uploadError
            w.appendChild(err)
        }

        // Chú thích
        const captionLabel = document.createElement('div')
        captionLabel.className = 'le-video__label'
        captionLabel.textContent = 'Chú thích (tuỳ chọn)'
        const caption = document.createElement('input')
        caption.type = 'text'
        caption.placeholder = 'VD: Demo tạo landing page trong 10 phút'
        caption.value = this.data.caption
        caption.readOnly = this.readOnly
        caption.oninput = () => { this.data.caption = caption.value }
        w.append(captionLabel, caption)

        // Mốc thời gian
        const chaptersLabel = document.createElement('div')
        chaptersLabel.className = 'le-video__label'
        chaptersLabel.textContent = 'Mốc thời gian — mỗi dòng "mm:ss Nội dung"; bấm mốc trên app sẽ tua tới đó'
        const chapters = document.createElement('textarea')
        chapters.rows = 5
        chapters.placeholder = '00:00 Giới thiệu\n02:15 Cài đặt công cụ\n10:40 Thực hành'
        chapters.value = this.data.chapters
        chapters.readOnly = this.readOnly
        const errors = document.createElement('div')
        errors.className = 'le-video__error'
        const check = () => {
            const list = chapterErrors(chapters.value, this.state?.status === 'ready' ? this.state.duration : null)
            errors.textContent = list.slice(0, 3).join(' ') + (list.length > 3 ? ` (+${list.length - 3} lỗi khác)` : '')
        }
        chapters.oninput = () => { this.data.chapters = chapters.value; check() }
        // Enter trong ô mốc thời gian là xuống dòng, không tạo khối mới
        chapters.onkeydown = (e) => { if (e.key === 'Enter') e.stopPropagation() }
        w.append(chaptersLabel, chapters, errors)
        check()
    }
}
