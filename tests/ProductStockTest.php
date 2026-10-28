<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/Product.php';

final class ProductStockTest extends TestCase
{
    private PDO $db;
    private Product $product;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            quantity INTEGER NOT NULL,
            updated_at TEXT
        )");
        $this->db->exec("INSERT INTO products (id, quantity, updated_at) VALUES (1, 5, CURRENT_TIMESTAMP)");
        $this->db->exec("CREATE TABLE product_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            action_type TEXT NOT NULL,
            quantity_change INTEGER NOT NULL,
            previous_quantity INTEGER NOT NULL,
            new_quantity INTEGER NOT NULL,
            reference_type TEXT,
            reference_id INTEGER,
            notes TEXT,
            user_id INTEGER,
            created_at TEXT NOT NULL
        )");
        $this->product = new Product($this->db);
    }

    public function testStockInAndOutUseCurrentQuantityAndWriteHistory(): void
    {
        self::assertTrue($this->product->addStock(1, 3, 'purchase', 9, 'Delivery'));
        self::assertTrue($this->product->removeStock(1, 2, 'sale', 12, 'Sale'));

        self::assertSame(6, (int) $this->db->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn());
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM product_history')->fetchColumn());
        self::assertSame(3, (int) $this->db->query("SELECT quantity_change FROM product_history WHERE action_type = 'stock_in'")->fetchColumn());
        self::assertSame(-2, (int) $this->db->query("SELECT quantity_change FROM product_history WHERE action_type = 'stock_out'")->fetchColumn());
    }

    public function testInsufficientStockDoesNotChangeQuantityOrHistory(): void
    {
        self::assertFalse($this->product->removeStock(1, 6, 'sale', 13));

        self::assertSame(5, (int) $this->db->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM product_history')->fetchColumn());
    }

    public function testAbsoluteQuantityUpdateRejectsStaleImportValue(): void
    {
        self::assertFalse($this->product->update(1, [
            'quantity' => 9,
            'expected_quantity' => 4,
        ]));

        self::assertSame(5, (int) $this->db->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn());
    }

    public function testHistoryFailureRollsBackStockMovement(): void
    {
        $this->db->exec('DROP TABLE product_history');

        self::assertFalse($this->product->addStock(1, 4, 'purchase', 10));
        self::assertSame(5, (int) $this->db->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn());
        self::assertFalse($this->db->inTransaction());
    }

    public function testStockMovementJoinsCallerTransaction(): void
    {
        $this->db->beginTransaction();
        self::assertTrue($this->product->addStock(1, 4, 'purchase', 11));
        $this->db->rollBack();

        self::assertSame(5, (int) $this->db->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM product_history')->fetchColumn());
    }
}
