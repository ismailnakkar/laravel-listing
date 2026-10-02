<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Listing\Listing;
use Listing\Tests\Fixtures\Kind;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class ScreenTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/products', function (Request $request) {
            $listing = Listing::for(Product::query(), $request)
                ->search('q', ['name', 'reference'])
                ->enum('status', Status::class)
                ->enums('kind', Kind::class)
                ->flag('paid')
                ->id()
                ->date('from', 'day', '>=')
                ->date('to', 'day', '<=')
                ->sorts('name', 'price');

            return view('products', ['products' => $listing->paginate(3), 'listing' => $listing]);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['Desk', 30, 1, true, '2026-01-02'], ['Floor', 10, 0, false, '2026-01-03'],
            ['Wall', 20, 1, false, '2026-01-04'], ['Table', 10, 0, true, '2026-01-05']] as [$name, $price, $status, $paid, $day]) {
            Product::create(['name' => $name, 'price' => $price, 'status' => $status, 'paid' => $paid, 'day' => $day, 'kind' => 'book']);
        }
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        preg_match_all('#<td data-name>(.*?)</td>#', (string)$response->getContent(), $matches);

        return $matches[1];
    }

    public function test_filters_sort_and_page_through_http(): void
    {
        $this->assertSame(['Desk', 'Table'], $this->names($this->get('/products?paid=1&sort=-price')->assertOk()));
        $this->assertSame(['Desk'], $this->names($this->get('/products?page=2')->assertOk()));
    }

    public function test_the_form_refills_from_the_narrowed_values(): void
    {
        $html = (string)$this->get('/products?q=%20desk%20&status=1&kind[]=film&kind[]=nope&paid=0&from=2026-01-02&to=2026-02-30&sort=-price')->assertOk()->getContent();

        $this->assertStringContainsString('name="q" value="desk"', $html);
        $this->assertStringContainsString('<option value="1" selected>live</option>', $html);
        $this->assertStringContainsString('<option value="0" selected>Paid: no</option>', $html);
        $this->assertStringContainsString('<option value="film" selected>film</option>', $html);
        $this->assertStringContainsString('<option value="book" >book</option>', $html);
        $this->assertStringContainsString('name="from" value="2026-01-02"', $html);
        $this->assertStringContainsString('name="to" value=""', $html);
        $this->assertStringContainsString('name="sort" value="-price"', $html);
    }

    public function test_an_empty_form_submission_shows_the_default_list(): void
    {
        $response = $this->get('/products?q=&status=&paid=&from=&to=&sort=')->assertOk();

        $this->assertSame(['Table', 'Wall', 'Floor'], $this->names($response));
        $this->assertStringNotContainsString('selected', (string)$response->getContent());
    }

    public function test_headers_link_the_toggled_sort_and_drop_the_page(): void
    {
        $html = (string)$this->get('/products?tab=open&sort=price&page=2')->assertOk()->getContent();

        $this->assertStringContainsString('href="http://localhost/products?tab=open&amp;sort=-price"', $html);
        $this->assertStringContainsString('href="http://localhost/products?tab=open&amp;sort=-name"', $html);
        $this->assertSame(1, substr_count($html, 'aria-sort="ascending"'));
    }

    /** @return iterable<string, array{string}> */
    public static function hostile(): iterable
    {
        foreach ([
            'q[]=x', 'q[a][b]=x', 'q=%FF', 'q=a%00b', 'q=' . str_repeat('a', 300),
            'id=99999999999999999999', 'id[]=1', 'status=nope', 'status=1x', 'status[]=1', 'kind=BOOK', 'kind[]=BOOK', 'kind[][]=book', 'kind[]=%FF', 'paid=on', 'paid[]=1',
            'sort=--price', 'sort[]=price', 'sort=password', 'sort=%FF',
            'page=9223372036854775807', 'page[]=2', 'page=-1',
            'from=2026-02-30', 'to=2026-13-01', 'from[]=2026-01-02', 'to=10000-01-01', 'from=0000-01-01', 'from=2026-01%0002',
        ] as $query) {
            yield $query => [$query];
        }
    }

    #[DataProvider('hostile')]
    public function test_hostile_input_shows_the_default_list(string $query): void
    {
        $response = $this->get('/products?' . $query)->assertOk();
        $html = (string)$response->getContent();

        $this->assertSame(['Table', 'Wall', 'Floor'], $this->names($response));
        $this->assertStringContainsString('name="from" value=""', $html);
        $this->assertStringContainsString('name="to" value=""', $html);
    }
}
