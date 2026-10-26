/**
 * Khối "Câu hỏi" cho Editor.js — câu hỏi trắc nghiệm trong bài học.
 *
 * Dữ liệu lưu: { type: 'single'|'multiple', question, options: [{ id, text, correct }], explanation }
 *  - Chọn 1: đúng 1 đáp án đúng. Chọn nhiều: ít nhất 1 đáp án đúng, học viên phải chọn đủ và đúng.
 *  - Học viên hoàn thành bài khi đọc hết và trả lời đúng mọi câu hỏi (server chấm, app không nhận đáp án đúng).
 * Kiểm tra lại ở server: App\Support\LessonContent.
 */

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'

const STYLE_ID = 'le-quiz-style'
const CSS = `
.le-quiz { border: 1px solid var(--gray-200, #e5e7eb); border-radius: 12px; padding: 14px 16px; margin: 6px 0; background: color-mix(in oklab, var(--primary-500, #10b981) 5%, transparent); }
.dark .le-quiz { border-color: var(--gray-700, #374151); }
.le-quiz__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.le-quiz__badge { font-size: 12px; font-weight: 600; color: var(--primary-700, #047857); text-transform: uppercase; letter-spacing: .04em; }
.dark .le-quiz__badge { color: var(--primary-400, #34d399); }
.le-quiz__types { display: inline-flex; border: 1px solid var(--gray-300, #d1d5db); border-radius: 8px; overflow: hidden; }
.dark .le-quiz__types { border-color: var(--gray-600, #4b5563); }
.le-quiz__types button { font-size: 12px; padding: 4px 10px; background: transparent; color: inherit; cursor: pointer; }
.le-quiz__types button.is-active { background: var(--primary-600, #059669); color: #fff; }
.le-quiz textarea, .le-quiz input[type=text] { width: 100%; border: 1px solid var(--gray-300, #d1d5db); border-radius: 8px; padding: 7px 10px; font-size: 14px; background: var(--white, #fff); color: inherit; resize: vertical; }
.dark .le-quiz textarea, .dark .le-quiz input[type=text] { background: var(--gray-900, #111827); border-color: var(--gray-600, #4b5563); }
.le-quiz__question { font-weight: 600; min-height: 40px; }
.le-quiz__label { font-size: 12px; color: var(--gray-500, #6b7280); margin: 12px 0 6px; }
.le-quiz__option { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.le-quiz__option input[type=radio], .le-quiz__option input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--primary-600, #059669); flex: none; cursor: pointer; }
.le-quiz__option.is-correct input[type=text] { border-color: var(--primary-500, #10b981); background: color-mix(in oklab, var(--primary-500, #10b981) 8%, var(--white, #fff)); }
.dark .le-quiz__option.is-correct input[type=text] { background: color-mix(in oklab, var(--primary-500, #10b981) 15%, var(--gray-900, #111827)); }
.le-quiz__remove { flex: none; width: 28px; height: 28px; border-radius: 6px; color: var(--gray-400, #9ca3af); cursor: pointer; background: transparent; font-size: 18px; line-height: 1; }
.le-quiz__remove:hover { color: var(--danger-600, #dc2626); background: color-mix(in oklab, var(--danger-500, #ef4444) 10%, transparent); }
.le-quiz__add { font-size: 13px; color: var(--primary-700, #047857); background: transparent; cursor: pointer; padding: 4px 0; }
.dark .le-quiz__add { color: var(--primary-400, #34d399); }
.le-quiz__hint { font-size: 12px; color: var(--gray-500, #6b7280); margin-top: 4px; }
.le-quiz__error { font-size: 12px; color: var(--danger-600, #dc2626); margin-top: 6px; }
`

function uid() {
    return Math.random().toString(36).slice(2, 10)
}

export default class QuizTool {
    static get toolbox() {
        return { title: 'Câu hỏi', icon: ICON }
    }

    /** Giữ nguyên dữ liệu khi người soạn bấm Enter trong ô nhập. */
    static get enableLineBreaks() {
        return true
    }

    constructor({ data, readOnly }) {
        this.readOnly = readOnly
        const options = Array.isArray(data?.options) && data.options.length
            ? data.options.map((o) => ({ id: o.id || uid(), text: o.text || '', correct: !! o.correct }))
            : [{ id: uid(), text: '', correct: true }, { id: uid(), text: '', correct: false }]

        this.data = {
            type: data?.type === 'multiple' ? 'multiple' : 'single',
            question: data?.question || '',
            options,
            explanation: data?.explanation || '',
        }
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
        this.wrapper.className = 'le-quiz'
        this.draw()

        return this.wrapper
    }

    draw() {
        const d = this.data
        const w = this.wrapper
        w.innerHTML = ''

        // Đầu khối: nhãn + chọn kiểu câu hỏi
        const head = el('div', 'le-quiz__head')
        head.appendChild(el('span', 'le-quiz__badge', 'Câu hỏi trắc nghiệm'))
        const types = el('div', 'le-quiz__types')
        ;[['single', 'Chọn 1'], ['multiple', 'Chọn nhiều']].forEach(([value, label]) => {
            const button = el('button', d.type === value ? 'is-active' : '', label)
            button.type = 'button'
            button.addEventListener('click', () => {
                if (d.type === value) return
                d.type = value
                // Chuyển sang "Chọn 1" mà đang có nhiều đáp án đúng → giữ đáp án đúng đầu tiên
                if (value === 'single') {
                    let seen = false
                    d.options.forEach((o) => { if (o.correct) { o.correct = ! seen; seen = true } })
                }
                this.draw()
            })
            types.appendChild(button)
        })
        head.appendChild(types)
        w.appendChild(head)

        const question = el('textarea', 'le-quiz__question')
        question.rows = 2
        question.placeholder = 'Nội dung câu hỏi'
        question.value = d.question
        question.addEventListener('input', () => { d.question = question.value })
        w.appendChild(question)

        w.appendChild(el('div', 'le-quiz__label', d.type === 'single'
            ? 'Đáp án — đánh dấu 1 đáp án đúng'
            : 'Đáp án — đánh dấu mọi đáp án đúng'))

        const group = 'q' + uid()
        d.options.forEach((option, index) => {
            const row = el('div', 'le-quiz__option' + (option.correct ? ' is-correct' : ''))
            const mark = document.createElement('input')
            mark.type = d.type === 'single' ? 'radio' : 'checkbox'
            mark.name = group
            mark.checked = option.correct
            mark.title = 'Đáp án đúng'
            mark.addEventListener('change', () => {
                if (d.type === 'single') d.options.forEach((o) => { o.correct = o === option })
                else option.correct = mark.checked
                this.draw()
            })
            row.appendChild(mark)

            const text = document.createElement('input')
            text.type = 'text'
            text.placeholder = 'Đáp án ' + (index + 1)
            text.value = option.text
            text.addEventListener('input', () => { option.text = text.value })
            row.appendChild(text)

            const remove = el('button', 'le-quiz__remove', '×')
            remove.type = 'button'
            remove.title = 'Xoá đáp án'
            remove.disabled = d.options.length <= 2
            remove.style.visibility = d.options.length <= 2 ? 'hidden' : 'visible'
            remove.addEventListener('click', () => {
                d.options.splice(index, 1)
                if (d.type === 'single' && ! d.options.some((o) => o.correct)) d.options[0].correct = true
                this.draw()
            })
            row.appendChild(remove)
            w.appendChild(row)
        })

        if (d.options.length < 8) {
            const add = el('button', 'le-quiz__add', '+ Thêm đáp án')
            add.type = 'button'
            add.addEventListener('click', () => {
                d.options.push({ id: uid(), text: '', correct: false })
                this.draw()
                const inputs = w.querySelectorAll('.le-quiz__option input[type=text]')
                inputs[inputs.length - 1]?.focus()
            })
            w.appendChild(add)
        }

        w.appendChild(el('div', 'le-quiz__label', 'Giải thích (hiện sau khi học viên trả lời)'))
        const explanation = el('textarea')
        explanation.rows = 2
        explanation.placeholder = 'VD: Vì … (không bắt buộc)'
        explanation.value = d.explanation
        explanation.addEventListener('input', () => { d.explanation = explanation.value })
        w.appendChild(explanation)

        const problem = this.problem()
        if (problem) w.appendChild(el('div', 'le-quiz__error', problem))
    }

    /** Lỗi hiện dưới khối (khối lỗi vẫn được lưu nháp; server bỏ qua câu hỏi chưa hợp lệ khi chấm). */
    problem() {
        const filled = this.data.options.filter((o) => o.text.trim() !== '')
        if (filled.length < 2) return 'Cần ít nhất 2 đáp án có nội dung.'
        if (! filled.some((o) => o.correct)) return 'Chưa đánh dấu đáp án đúng.'

        return null
    }

    save() {
        return {
            type: this.data.type,
            question: this.data.question.trim(),
            options: this.data.options
                .filter((o) => o.text.trim() !== '')
                .map((o) => ({ id: o.id, text: o.text.trim(), correct: !! o.correct })),
            explanation: this.data.explanation.trim(),
        }
    }
}

function el(tag, className = '', text = null) {
    const node = document.createElement(tag)
    if (className) node.className = className
    if (text !== null) node.textContent = text

    return node
}
