<form method="get">
    <input type="search" name="q" value="{{ $listing->values['q'] }}">
    <select name="status">
        <option value="">Any status</option>
        @foreach (\Listing\Tests\Fixtures\Status::cases() as $case)
            <option value="{{ $case->value }}" @selected($listing->values['status'] === $case)>{{ $case->name }}</option>
        @endforeach
    </select>
    <select name="kind[]" multiple>
        @foreach (\Listing\Tests\Fixtures\Kind::cases() as $kind)
            <option value="{{ $kind->value }}" @selected(in_array($kind, $listing->values['kind'], true))>{{ $kind->name }}</option>
        @endforeach
    </select>
    <select name="paid">
        <option value="">Paid: any</option>
        <option value="1" @selected($listing->values['paid'] === true)>Paid: yes</option>
        <option value="0" @selected($listing->values['paid'] === false)>Paid: no</option>
    </select>
    <input type="date" name="from" value="{{ $listing->values['from'] }}">
    <input type="date" name="to" value="{{ $listing->values['to'] }}">
    <input type="hidden" name="sort" value="{{ $listing->sort }}">
    <button>Filter</button>
</form>
<table>
    <thead>
        <tr>
            @foreach (['name' => 'Name', 'price' => 'Price'] as $column => $label)
                <th @if ($listing->ariaSort($column)) aria-sort="{{ $listing->ariaSort($column) }}" @endif>
                    <a href="{{ $listing->sortUrl($column) }}">{{ $label }}</a>
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($products as $product)
            <tr><td data-name>{{ $product->name }}</td></tr>
        @endforeach
    </tbody>
</table>
