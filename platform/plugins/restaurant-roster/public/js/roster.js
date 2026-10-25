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

    const render = (data) => {
        $grid.html(data.html)
        state.week = data.week
        $('#roster-week-label').contents().last().replaceWith(` ${data.week_label}`)
        // Nút "Tự động xếp lịch" mở đúng tuần đang xem.
        $app.find('[data-auto-link]').each((_, link) => {
            link.href = `${link.dataset.autoBase}?week=${data.week}`
        })
        syncUrl()
    }

    const request = (method, url, payload = {}) => {
        $grid.addClass('is-loading')

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

                render(response.data)

                if (method !== 'GET' && response.message) {
                    Botble.showSuccess(response.message)
                }
            })
            .fail((xhr) => Botble.handleError(xhr))
            .always(() => $grid.removeClass('is-loading'))
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

    $app.on('click', '[data-roster-action]', (event) => {
        const $button = $(event.currentTarget)
        const confirmText = $button.data('confirm')

        if (confirmText && !window.confirm(confirmText)) {
            return
        }

        const url = { copy: urls.copyUrl, publish: urls.publishUrl, clear: urls.clearUrl, swap: urls.swapUrl }[$button.data('roster-action')]

        Botble.showButtonLoading($button)
        request('POST', url).always(() => Botble.hideButtonLoading($button))
    })

    // Xóa lịch theo hàng (1 nhân viên cả tuần) hoặc cột (1 ngày của outlet chính) để xếp tay lại.
    const $undo = $('#roster-undo')

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

                if (!info.total && !info.kept) {
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

                if (!total) {
                    Botble.showError('Chỉ có ô đã khóa / nghỉ phép – không xóa gì.')

                    return
                }

                const published = info.published ? `\nTrong đó có ${info.published} ca ĐÃ CÔNG BỐ.` : ''

                if (!window.confirm(`Xóa ${total} ô lịch của ${info.label}?${published}\n\nCó thể hoàn tác trong 30 phút.`)) {
                    return
                }

                request('POST', urls.clearCellsUrl, { ...payload, include_locked: includeLocked ? 1 : 0 }).done((result) => {
                    if (result.data && result.data.undo) {
                        $('#roster-undo-label').text(result.data.undo)
                        $undo.removeClass('d-none')
                    }
                })
            })
            .fail((xhr) => Botble.handleError(xhr))
    }

    $grid.on('click', '[data-roster-clear]', (event) => {
        event.stopPropagation()
        const $button = $(event.currentTarget)

        clearCells($button.data('roster-clear') === 'row'
            ? { mode: 'row', employee_id: $button.data('employee') }
            : { mode: 'column', outlet_id_clear: $button.data('outlet'), date: $button.data('date') })
    })

    $app.on('click', '[data-roster-undo]', () => {
        request('POST', urls.clearUndoUrl).done(() => $undo.addClass('d-none'))
    })

    $app.on('click', '[data-roster-undo-dismiss]', () => $undo.addClass('d-none'))

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

        $('#roster-cell-title').text($cell.data('employee-name'))
        $('#roster-cell-subtitle').text($cell.data('date-label'))
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
        }).done((response) => {
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
        }).done((response) => {
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
        }).done((response) => {
            if (!response.error) {
                $modal.modal('hide')
            }
        })
    })

    $modal.on('click', '[data-off-remove]', (event) => {
        if (!currentCell || !window.confirm($(event.currentTarget).data('confirm'))) {
            return
        }

        request('POST', urls.offRemoveUrl, { employee_id: currentCell.employee_id, date: currentCell.date }).done((response) => {
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
        }).done((response) => {
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
