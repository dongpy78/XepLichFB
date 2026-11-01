$(() => {
    const $app = $('#roster-app')

    if (!$app.length) {
        return
    }

    const $grid = $('#roster-grid')
    const $modal = $('#roster-cell-modal')
    const urls = $app.data()
    const csrf = $('meta[name="csrf-token"]').attr('content')

    const state = {
        week: $app.data('week'),
        outlet_id: $('#roster-outlet').val() || '',
    }

    let currentCell = null

    const shiftWeek = (weeks) => {
        if (weeks === 0) {
            const now = new Date()

            return [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-')
        }

        const [y, m, d] = state.week.split('-').map(Number)
        const date = new Date(Date.UTC(y, m - 1, d + weeks * 7))

        return date.toISOString().slice(0, 10)
    }

    const syncUrl = () => {
        const params = new URLSearchParams()

        params.set('week', state.week)

        if (state.outlet_id) {
            params.set('outlet_id', state.outlet_id)
        }

        window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`)
    }

    // Ô trên bảng theo nhân viên / ngày.
    const findCell = (employeeId, date) => $grid.find(`.roster-cell[data-employee="${employeeId}"][data-date="${date}"]`)

    // Nháy sáng một ô / hàng để mắt tìm thấy ngay (sau khi lưu, khi bấm cảnh báo).
    const flash = ($el) => {
        $el.removeClass('is-flash')
        void $el[0]?.offsetWidth
        $el.addClass('is-flash')
        window.setTimeout(() => $el.removeClass('is-flash'), 1800)
    }

    let applyFilters = () => {}

    const render = (data, focus = null) => {
        // Giữ vị trí cuộn ngang của bảng (màn hình hẹp) khi vẽ lại.
        const scrollLeft = $grid.find('.roster-scroll').scrollLeft() || 0

        $grid.html(data.html)
        $grid.find('.roster-scroll').scrollLeft(scrollLeft)
        state.week = data.week
        $('#roster-week-label').contents().last().replaceWith(` ${data.week_label}`)
        // Nút "Tự động xếp lịch" mở đúng tuần đang xem.
        $app.find('[data-auto-link]').each((_, link) => {
            link.href = `${link.dataset.autoBase}?week=${data.week}`
        })
        syncUrl()
        applyFilters()

        if (focus) {
            flash(findCell(focus.employee_id, focus.date))
        }
    }

    // Thanh hoàn tác: lần xóa lịch (clear) hoặc lần sửa ô gần nhất (edit).
    const $undo = $('#roster-undo')
    let undoKind = null

    const showUndo = (kind, text) => {
        undoKind = kind
        $('#roster-undo-text').text(text)
        $undo.removeClass('d-none')
    }

    const hideUndo = () => {
        undoKind = null
        $undo.addClass('d-none')
    }

    // options.cell: thao tác trên một ô → chỉ ô đó hiện "đang lưu" (bảng vẫn dùng được), vẽ lại xong ô được nháy sáng.
    const request = (method, url, payload = {}, options = {}) => {
        const $cell = options.cell ? findCell(options.cell.employee_id, options.cell.date) : null

        if ($cell && $cell.length) {
            $cell.addClass('is-saving')
        } else {
            $grid.addClass('is-loading')
        }

        return $.ajax({
            url,
            method,
            data: { ...state, ...payload },
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        })
            .done((response) => {
                if (response.error) {
                    Botble.showError(response.message)

                    return
                }

                render(response.data, options.cell)

                if (response.data.edit_undo) {
                    showUndo('edit', `Đã sửa ô ${response.data.edit_undo}. Bấm Hoàn tác (hoặc Ctrl+Z) nếu sửa nhầm.`)
                } else if (response.data.edit_undone && undoKind === 'edit') {
                    hideUndo()
                }

                if (method !== 'GET' && response.message) {
                    Botble.showSuccess(response.message)
                }
            })
            .fail((xhr) => Botble.handleError(xhr))
            .always(() => {
                $grid.removeClass('is-loading')
                $cell?.removeClass('is-saving')
            })
    }

    const load = () => request('GET', urls.gridUrl)

    $app.on('click', '[data-roster-week]', (event) => {
        state.week = shiftWeek(Number($(event.currentTarget).data('roster-week')))
        load()
    })

    $('#roster-outlet').on('change', (event) => {
        state.outlet_id = $(event.currentTarget).val() || ''
        load()
    })

    // ---------------------------------------------------------------- Bộ lọc trên bảng (chạy trên trình duyệt, không gọi máy chủ)
    const $filters = $('#roster-filters')
    const filter = { search: '', position: '', gender: '', code: '', transfer: false, warn: false, unscheduled: false }
    // Bỏ dấu tiếng Việt: gõ "phien" vẫn tìm ra "Phiên".
    const plain = (text) => String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase()
    // Mã ca của ô: "M6(L)+2" → "M6", "OFF" → "OFF", nghỉ phép → mã loại nghỉ.
    const codeOf = (cell) => String(cell.dataset.notation || '').split(/[(+]/)[0]

    const fillSelect = ($select, options, value) => {
        $select.find('option:not(:first)').remove()
        options.forEach(([optionValue, label]) => $select.append(new Option(label, optionValue)))
        $select.val(options.some(([optionValue]) => String(optionValue) === String(value)) ? value : '')
    }

    applyFilters = () => {
        if (!$filters.length) {
            return
        }

        const $rows = $grid.find('tr.roster-row')
        const positions = new Map()
        const codes = new Set()

        $rows.each((_, row) => {
            if (row.dataset.position) {
                positions.set(row.dataset.position, row.dataset.positionName || '—')
            }

            String(row.dataset.codes || '').split(' ').filter(Boolean).forEach((code) => codes.add(code))
        })

        // Danh sách vị trí / ca lấy từ chính bảng đang xem (đổi tuần, đổi nhà hàng vẫn đúng); giữ lựa chọn nếu còn.
        fillSelect($('#rf-position'), [...positions].sort((a, b) => a[1].localeCompare(b[1], 'vi')), filter.position)
        fillSelect($('#rf-code'), [...codes].sort().map((code) => [code, code]), filter.code)
        filter.position = $('#rf-position').val() || ''
        filter.code = $('#rf-code').val() || ''

        const query = plain(filter.search.trim())
        const active = Boolean(query || filter.position || filter.gender || filter.code || filter.transfer || filter.warn || filter.unscheduled)
        let shown = 0

        $rows.each((_, row) => {
            const d = row.dataset
            const visible = (!query || plain(d.employeeName).includes(query))
                && (!filter.position || d.position === filter.position)
                && (!filter.gender || d.gender === filter.gender)
                && (!filter.code || String(d.codes || '').split(' ').includes(filter.code))
                && (!filter.transfer || d.transfer === '1')
                && (!filter.warn || d.warn === '1')
                && (!filter.unscheduled || d.unscheduled === '1')

            row.classList.toggle('d-none', !visible)
            shown += visible ? 1 : 0
        })

        // Khu không còn ai hiện: ẩn dòng tên khu.
        let $section = null
        let sectionHasRows = false
        const closeSection = () => $section && $section.toggleClass('d-none', active && !sectionHasRows)

        $grid.find('tbody > tr').each((_, row) => {
            if (row.classList.contains('roster-section')) {
                closeSection()
                $section = $(row)
                sectionHasRows = false
            } else if (row.classList.contains('roster-row') && !row.classList.contains('d-none')) {
                sectionHasRows = true
            }
        })
        closeSection()

        // Lọc theo ca: làm mờ các ô khác ca đang lọc để thấy ngay ai làm ca đó ngày nào.
        $grid.find('.roster-cell').each((_, cell) => cell.classList.toggle('is-dimmed', Boolean(filter.code) && codeOf(cell) !== filter.code))
        $grid.toggleClass('is-filtering', active)
        $('#rf-count').text(active ? `Hiển thị ${shown}/${$rows.length} nhân viên` : `${$rows.length} nhân viên`)
        $('#rf-reset').toggleClass('d-none', !active)
    }

    const resetFilters = () => {
        Object.assign(filter, { search: '', position: '', gender: '', code: '', transfer: false, warn: false, unscheduled: false })
        $('#rf-search').val('')
        $filters.find('select').val('')
        $filters.find('input[type="checkbox"]').prop('checked', false)
        applyFilters()
    }

    let searchTimer = null

    $('#rf-search').on('input', (event) => {
        window.clearTimeout(searchTimer)
        searchTimer = window.setTimeout(() => {
            filter.search = event.target.value
            applyFilters()
        }, 120)
    })
    $('#rf-position, #rf-gender, #rf-code').on('change', (event) => {
        filter[event.target.id.slice(3)] = event.target.value
        applyFilters()
    })
    $('#rf-transfer, #rf-warn, #rf-unscheduled').on('change', (event) => {
        filter[event.target.id.slice(3)] = event.target.checked
        applyFilters()
    })
    $('#rf-reset').on('click', resetFilters)
    applyFilters()

    // Bảng cảnh báo: bấm một dòng → cuộn tới đúng ô (hoặc hàng nhân viên) và nháy sáng; bỏ lọc nếu ô đang bị ẩn.
    $grid.on('click', '[data-roster-jump]', (event) => {
        const d = event.currentTarget.dataset
        const target = () => d.date ? findCell(d.employee, d.date) : $grid.find(`tr.roster-row[data-employee-id="${d.employee}"] > td:first-child`)
        let $target = target()

        if ($target.closest('tr').hasClass('d-none')) {
            resetFilters()
            $target = target()
        }

        if ($target.length) {
            $target[0].scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' })
            flash($target)
        }
    })

    // Công bố lịch tuần: xem trước (tuần, phạm vi, số nhân viên, lỗi chặn công bố) → xác nhận → công bố (lưu phiên bản chính thức).
    const publishWithPreview = ($button) => {
        Botble.showButtonLoading($button)
        $.ajax({ url: urls.publishPreviewUrl, method: 'GET', data: { ...state }, headers: { Accept: 'application/json' } })
            .done((response) => {
                Botble.hideButtonLoading($button)
                const d = response.data || {}

                if (d.problems && d.problems.length) {
                    Botble.showError(`Chưa công bố được – cần sửa ${d.problems.length} lỗi: ${d.problems.slice(0, 6).join(' · ')}${d.problems.length > 6 ? ' …' : ''}`)

                    return
                }

                const text = [
                    `Công bố lịch tuần ${d.week}?`,
                    `Phạm vi: ${d.scope}`,
                    `Nhân viên có lịch: ${d.employees}${d.empty ? ` (${d.empty} người chưa có ca nào)` : ''}`,
                    `Trạng thái hiện tại: ${d.status}`,
                    '',
                    `Lịch sẽ thành phiên bản chính thức v${d.next_version} (nếu có thay đổi) để tải PNG / Excel / PDF gửi nhân viên.`,
                ].join('\n')

                if (window.confirm(text)) {
                    Botble.showButtonLoading($button)
                    request('POST', urls.publishUrl).always(() => Botble.hideButtonLoading($button))
                }
            })
            .fail((xhr) => {
                Botble.hideButtonLoading($button)
                Botble.handleError(xhr)
            })
    }

    $app.on('click', '[data-roster-action]', (event) => {
        const $button = $(event.currentTarget)
        const confirmText = $button.data('confirm')

        if ($button.data('roster-action') === 'publish') {
            publishWithPreview($button)

            return
        }

        if (confirmText && !window.confirm(confirmText)) {
            return
        }

        const action = $button.data('roster-action')
        const url = { copy: urls.copyUrl, publish: urls.publishUrl, clear: urls.clearUrl, swap: urls.swapUrl, merge: urls.gridMergeUrl, status: urls.outletStatusUrl }[action]
        // Hiển thị chung Villa + Gusto: outlet khu hiển thị chung (trống = hiển thị riêng).
        // Mở / đóng cửa nhà hàng: outlet, các ngày (cách nhau dấu phẩy), đóng (1) / mở (0).
        const payload = {
            merge: { outlet_id: $button.data('merge-outlet') || '' },
            status: { status_outlet_id: $button.data('status-outlet'), dates: String($button.data('status-dates')).split(','), closed: $button.data('status-closed') },
        }[action] || {}

        Botble.showButtonLoading($button)
        request('POST', url, payload).always(() => Botble.hideButtonLoading($button))
    })

    // Xóa lịch theo hàng (1 nhân viên cả tuần) hoặc cột (1 ngày của outlet chính) để xếp tay lại.
    const clearCells = (payload) => {
        $.ajax({
            url: urls.clearCellsUrl,
            method: 'POST',
            data: { ...state, ...payload, preview: 1 },
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        })
            .done((response) => {
                if (response.error) {
                    Botble.showError(response.message)

                    return
                }

                const info = response.data

                if (!info.total && !info.kept && !info.versions) {
                    Botble.showError(`Không có ô nào để xóa ở ${info.label}.`)

                    return
                }

                let includeLocked = false

                if (info.kept > 0) {
                    includeLocked = window.confirm(
                        `${info.label} có ${info.kept} ô đã khóa / nghỉ phép.\n\n` +
                        'OK = xóa cả các ô này.\nCancel = giữ lại (khuyên dùng).'
                    )
                }

                const total = info.total + (includeLocked ? info.kept : 0)

                if (!total && !info.versions) {
                    Botble.showError('Chỉ có ô đã khóa / nghỉ phép – không xóa gì.')

                    return
                }

                const published = (info.published ? `\nTrong đó có ${info.published} ca ĐÃ CÔNG BỐ.` : '')
                    + (info.versions ? `\nXóa luôn ${info.versions} bản lịch đã công bố (ảnh / Excel / PDF) – tuần trở về Bản nháp.` : '')

                if (!window.confirm(`Xóa ${total} ô lịch của ${info.label}?${published}\n\nCó thể hoàn tác trong 30 phút.`)) {
                    return
                }

                request('POST', urls.clearCellsUrl, { ...payload, include_locked: includeLocked ? 1 : 0 }).done((result) => {
                    if (result.data && result.data.undo) {
                        showUndo('clear', `Đã xóa lịch của ${result.data.undo} – xếp tay lại các ô trống, hoặc hoàn tác nếu xóa nhầm.`)
                    }
                })
            })
            .fail((xhr) => Botble.handleError(xhr))
    }

    // Nút "Xóa lịch tuần" trên thanh công cụ: xóa cả tuần đang xem (cả ca đã công bố), có xem trước, hỏi giữ ô khóa, hoàn tác.
    $app.on('click', '[data-roster-clear-week]', () => clearCells({ mode: 'week' }))

    $grid.on('click', '[data-roster-clear]', (event) => {
        event.stopPropagation()
        const $button = $(event.currentTarget)

        clearCells($button.data('roster-clear') === 'row'
            ? { mode: 'row', employee_id: $button.data('employee') }
            : { mode: 'column', outlet_id_clear: $button.data('outlet'), date: $button.data('date') })
    })

    const undo = () => {
        if (undoKind === 'edit') {
            // Thành công hay bị từ chối (ô đã được sửa tiếp), máy chủ đều đã bỏ bản ghi nhớ → ẩn thanh hoàn tác.
            request('POST', urls.assignUndoUrl).always(hideUndo)
        } else if (undoKind === 'clear') {
            request('POST', urls.clearUndoUrl).done(() => hideUndo())
        }
    }

    $app.on('click', '[data-roster-undo]', undo)
    $app.on('click', '[data-roster-undo-dismiss]', hideUndo)

    // Ctrl+Z (ngoài ô nhập liệu / hộp thoại): hoàn tác lần sửa ô / xóa lịch gần nhất.
    $(document).on('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z' && undoKind
            && !$(event.target).is('input, textarea, select, [contenteditable]') && !$('.modal.show').length) {
            event.preventDefault()
            undo()
        }
    })

    // Ghi OFF trên bảng: bật chế độ chọn ô, bấm các ô cần OFF (Shift = chọn liền nhiều ngày của một người), rồi ghi & khóa.
    const $offBar = $('#roster-off-bar')
    const offSelection = new Map()
    let offSelecting = false
    let lastOffCell = null

    const cellKey = ($cell) => `${$cell.data('employee')}|${$cell.data('date')}`

    const paintOffSelection = () => {
        $grid.find('.roster-cell').each((_, cell) => {
            $(cell).toggleClass('is-off-selected', offSelection.has(cellKey($(cell))))
        })

        const count = offSelection.size
        $('#roster-off-count').text(count ? `Đã chọn ${count} ô` : 'Bấm vào các ô cần ghi')
        $offBar.find('[data-off-save]').prop('disabled', !count)
    }

    const setOffSelecting = (enabled) => {
        offSelecting = enabled
        offSelection.clear()
        lastOffCell = null
        $grid.toggleClass('is-off-selecting', enabled)
        $offBar.toggleClass('d-none', !enabled)
        $app.find('[data-off-select]').toggleClass('active', enabled)
        paintOffSelection()
    }

    const toggleOffCell = ($cell, value) => {
        const key = cellKey($cell)

        if (value ?? !offSelection.has(key)) {
            offSelection.set(key, { employee_id: $cell.data('employee'), date: $cell.data('date') })
        } else {
            offSelection.delete(key)
        }
    }

    // Giá trị "shift:<id>" = ca nhân viên xin (M6 / A2) → ghi ca & khóa; còn lại = loại OFF.
    const recordOff = (cells, type, reason) => String(type).startsWith('shift:')
        ? request('POST', urls.shiftRequestUrl, { cells, shift_template_id: String(type).slice(6), reason })
        : request('POST', urls.offRecordUrl, { cells, off_type: type, off_reason: reason })

    $app.on('click', '[data-off-select]', () => setOffSelecting(!offSelecting))
    $offBar.on('click', '[data-off-cancel]', () => setOffSelecting(false))

    $offBar.on('click', '[data-off-save]', (event) => {
        const $button = $(event.currentTarget)

        Botble.showButtonLoading($button)
        recordOff([...offSelection.values()], $('#roster-off-type').val(), $('#roster-off-reason').val())
            .done((response) => {
                if (!response.error) {
                    $('#roster-off-reason').val('')
                    setOffSelecting(false)
                }
            })
            .always(() => Botble.hideButtonLoading($button))
    })

    // Chạy trước handler mở hộp xếp ca: đang chọn OFF thì bấm ô = chọn / bỏ chọn.
    $grid.on('click', '.roster-cell', (event) => {
        if (!offSelecting) {
            return
        }

        event.stopImmediatePropagation()
        const $cell = $(event.currentTarget)

        if (event.shiftKey && lastOffCell && String(lastOffCell.data('employee')) === String($cell.data('employee'))) {
            // Chọn liền các ngày giữa ô trước và ô này trên cùng hàng nhân viên.
            const $row = $cell.closest('tr').find('.roster-cell')
            const [from, to] = [$row.index(lastOffCell), $row.index($cell)].sort((a, b) => a - b)
            $row.slice(from, to + 1).each((_, cell) => toggleOffCell($(cell), true))
        } else {
            toggleOffCell($cell)
        }

        lastOffCell = $cell
        paintOffSelection()
    })

    // Bảng được vẽ lại (đổi tuần, lưu ô...): tô lại các ô đang chọn.
    new MutationObserver(() => offSelecting && $grid.toggleClass('is-off-selecting', true) && paintOffSelection())
        .observe($grid[0], { childList: true })

    if (!$modal.length) {
        return
    }

    // Trong hộp xếp ca: nhân viên xin OFF ngày này → ghi OFF & khóa.
    $modal.on('click', '[data-off-quick]', () => {
        if (!currentCell) {
            return
        }

        recordOff([{ employee_id: currentCell.employee_id, date: currentCell.date }], 'off_request', $('#roster-cell-note').val()).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    // Trong hộp xếp ca: nhân viên xin ca M6 / A2 ngày này → ghi ca & khóa.
    $modal.on('click', '[data-shift-request]', (event) => {
        if (!currentCell) {
            return
        }

        recordOff([{ employee_id: currentCell.employee_id, date: currentCell.date }], `shift:${$(event.currentTarget).data('shift-request')}`, $('#roster-cell-note').val()).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    $grid.on('click', '.roster-table--editable .roster-cell', (event) => {
        const $cell = $(event.currentTarget)
        const warnings = $cell.data('warnings') || []

        currentCell = {
            employee_id: $cell.data('employee'),
            date: $cell.data('date'),
        }

        // Tên nhân viên ở <tr>, nhãn ngày ở <th> cùng cột (không lặp ở từng ô cho bảng gọn).
        $('#roster-cell-title').text($cell.closest('tr').data('employee-name'))
        $('#roster-cell-subtitle').text($grid.find('thead th').eq($cell.index()).data('date-label') || $cell.data('date'))
        $('#roster-cell-note').val($cell.data('note') || '')
        $('#roster-cell-notation').val($cell.data('notation') || '')
        $('#roster-cell-overtime').val($cell.data('overtime') || '')

        currentCell.locked = Number($cell.data('locked')) === 1

        const overtime = Number($cell.data('overtime')) || 0
        const otLabels = { approved: 'đã duyệt', rejected: 'đã từ chối' }
        $('#roster-cell-ot').toggleClass('d-none', overtime <= 0)
        $('#roster-cell-ot-hours').text(`+${overtime}h`)
        $('#roster-cell-ot-status').text(otLabels[$cell.data('ot-status')] || 'chờ duyệt')
        const recordedOff = Number($cell.data('recorded-off')) === 1
        const review = $cell.data('review') || ''
        const isShift = Boolean($cell.data('template'))

        // OFF quản lý đã cho: gỡ bằng "Gỡ OFF" (không mở khóa thường); ca làm: khóa = xác nhận ca.
        $('#roster-cell-recorded-off').toggleClass('d-none', !recordedOff)
        $('#roster-cell-review').toggleClass('d-none', !review)
        $('#roster-cell-review-text').text(review)
        $('#roster-cell-lock').toggleClass('d-none', recordedOff)
        $('#roster-cell-lock span').text(currentCell.locked ? 'Mở khóa ô' : (isShift ? 'Xác nhận & khóa ca' : 'Khóa ô'))
        $modal.find('[data-assign]').prop('disabled', currentCell.locked)

        const tags = $cell.data('tags') || []

        $modal.find('.roster-tag-input').each((_, input) => {
            input.checked = tags.includes(input.value)
        })

        $modal.find('[data-template]').each((_, item) => {
            $(item).toggleClass('is-current', String($(item).data('template')) === String($cell.data('template')))
        })

        const $warnings = $('#roster-cell-warnings')

        $warnings.toggleClass('d-none', !warnings.length).html(warnings.map((text) => $('<div>').text(text).prop('outerHTML')).join(''))

        $modal.modal('show')
    })

    $modal.on('shown.bs.modal', () => $('#roster-cell-notation').trigger('focus').trigger('select'))

    const assign = (action, extra = {}) => {
        if (!currentCell) {
            return
        }

        request('POST', urls.assignUrl, {
            ...currentCell,
            action,
            tags: $modal.find('.roster-tag-input:checked').map((_, input) => input.value).get(),
            overtime_hours: $('#roster-cell-overtime').val(),
            notation: $('#roster-cell-notation').val(),
            note: $('#roster-cell-note').val(),
            ...extra,
        }, { cell: currentCell }).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    }

    $modal.on('click', '[data-assign]', (event) => {
        const $button = $(event.currentTarget)

        assign($button.data('assign'), {
            shift_template_id: $button.data('template') || '',
            leave_type: $button.data('leave') || '',
        })
    })

    $modal.on('click', '[data-ot-status]', (event) => {
        if (!currentCell) {
            return
        }

        request('POST', urls.overtimeUrl, {
            employee_id: currentCell.employee_id,
            date: currentCell.date,
            status: $(event.currentTarget).data('ot-status'),
        }, { cell: currentCell }).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    $('#roster-cell-lock').on('click', () => {
        if (!currentCell) {
            return
        }

        request('POST', urls.lockUrl, {
            employee_id: currentCell.employee_id,
            date: currentCell.date,
            locked: currentCell.locked ? 0 : 1,
        }, { cell: currentCell }).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    $modal.on('click', '[data-off-remove]', (event) => {
        if (!currentCell || !window.confirm($(event.currentTarget).data('confirm'))) {
            return
        }

        request('POST', urls.offRemoveUrl, { employee_id: currentCell.employee_id, date: currentCell.date }, { cell: currentCell }).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    $modal.on('click', '[data-review]', (event) => {
        if (!currentCell) {
            return
        }

        request('POST', urls.reviewUrl, {
            employee_id: currentCell.employee_id,
            date: currentCell.date,
            decision: $(event.currentTarget).data('review'),
        }, { cell: currentCell }).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    $('#roster-cell-notation').on('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault()
            assign('notation')
        }
    })
})
