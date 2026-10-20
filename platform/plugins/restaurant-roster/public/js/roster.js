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

    if (!$modal.length) {
        return
    }

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
        $('#roster-cell-lock span').text(currentCell.locked ? 'Mở khóa ô' : 'Khóa ô')

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

    $('#roster-cell-notation').on('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault()
            assign('notation')
        }
    })
})
