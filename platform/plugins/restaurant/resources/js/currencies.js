class Currencies {
    constructor() {
        this.template = $('#currency_template').html()
        this.totalItem = 0
        this.deletedItems = []
        this.initData()
        this.handleForm()
    }

    initData() {
        const currencies = $.parseJSON($('#currencies').html())
        $.each(currencies, (index, currency) => {
            const currencyItem = this.template
                .replace(/__id__/gi, currency.id)
                .replace(/__position__/gi, currency.order)
                .replace(/__isPrefixSymbolChecked__/gi, currency.is_prefix_symbol == 1 ? 'selected' : '')
                .replace(/__notIsPrefixSymbolChecked__/gi, currency.is_prefix_symbol == 0 ? 'selected' : '')
                .replace(/__isDefaultChecked__/gi, currency.is_default == 1 ? 'checked' : '')
                .replace(/__title__/gi, currency.title)
                .replace(/__decimals__/gi, currency.decimals)
                .replace(/__exchangeRate__/gi, currency.exchange_rate)
                .replace(/__symbol__/gi, currency.symbol)

            $('.swatches-container .swatches-list').append(currencyItem)
            this.totalItem++
        })
    }

    addNewAttribute() {
        const currencyItem = this.template
            .replace(/__id__/gi, 0)
            .replace(/__position__/gi, this.totalItem)
            .replace(/__isPrefixSymbolChecked__/gi, '')
            .replace(/__notIsPrefixSymbolChecked__/gi, '')
            .replace(/__isDefaultChecked__/gi, this.totalItem == 0 ? 'checked' : '')
            .replace(/__title__/gi, '')
            .replace(/__decimals__/gi, 0)
            .replace(/__exchangeRate__/gi, 1)
            .replace(/__symbol__/gi, '')

        $('.swatches-container .swatches-list').append(currencyItem)
        this.totalItem++
    }

    exportData() {
        const data = []
        $('.swatches-container .swatches-list li').each((index, item) => {
            const $item = $(item)
            data.push({
                id: $item.data('id'),
                is_default: $item.find('[data-type=is_default] input[type=radio]').is(':checked') ? 1 : 0,
                order: $item.index(),
                title: $item.find('[data-type=title] input').val(),
                symbol: $item.find('[data-type=symbol] input').val(),
                decimals: $item.find('[data-type=decimals] input').val(),
                exchange_rate: $item.find('[data-type=exchange_rate] input').val(),
                is_prefix_symbol: $item.find('[data-type=is_prefix_symbol] select').val(),
            })
        })

        return data
    }

    handleForm() {
        $('.swatches-container .swatches-list').sortable()

        $('body')
            .on('submit', '.currency-setting-form', () => {
                const currencies = this.exportData()
                $('#currencies').val(JSON.stringify(currencies))
                $('#deleted_currencies').val(JSON.stringify(this.deletedItems))
            })
            .on('click', '.js-add-new-attribute', (event) => {
                event.preventDefault()
                this.addNewAttribute()
            })
            .on('click', '.swatches-container .swatches-list li .remove-item a', (event) => {
                event.preventDefault()
                const $item = $(event.currentTarget).closest('li')
                this.deletedItems.push($item.data('id'))
                $item.remove()
            })
    }
}

$(window).on('load', () => {
    new Currencies()
})