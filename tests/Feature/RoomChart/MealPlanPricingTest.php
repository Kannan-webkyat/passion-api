<?php

namespace Tests\Feature\RoomChart;

use App\Support\BookingInvoiceRoomStay;

class MealPlanPricingTest extends RoomChartTestCase
{
    public function test_half_board_charges_one_meal_and_full_board_charges_it_twice(): void
    {
        $roomType = $this->makeRoomType([
            'breakfast_price' => 250,
            'child_breakfast_price' => 125,
            'adult_meal_price' => 400,
            'child_meal_price' => 200,
            'adult_lunch_price' => 999,
            'adult_dinner_price' => 999,
        ]);

        $roomOnly = $this->makeRatePlan($roomType, ['name' => 'EP', 'meal_plan_type' => 'room_only']);
        $breakfast = $this->makeRatePlan($roomType, ['name' => 'CP', 'meal_plan_type' => 'breakfast']);
        $half = $this->makeRatePlan($roomType, ['name' => 'MAP', 'meal_plan_type' => 'half_board']);
        $full = $this->makeRatePlan($roomType, ['name' => 'AP', 'meal_plan_type' => 'full_board']);

        $this->assertSame(0.0, BookingInvoiceRoomStay::nightlyPlanMealsPreTax($roomType, $roomOnly, 2, 1));
        $this->assertSame(625.0, BookingInvoiceRoomStay::nightlyPlanMealsPreTax($roomType, $breakfast, 2, 1));
        $this->assertSame(1625.0, BookingInvoiceRoomStay::nightlyPlanMealsPreTax($roomType, $half, 2, 1));
        $this->assertSame(2625.0, BookingInvoiceRoomStay::nightlyPlanMealsPreTax($roomType, $full, 2, 1));
    }

    public function test_room_type_stores_breakfast_and_meal_prices(): void
    {
        $this->actingWith(['room-types-create', 'room-types-edit']);

        $created = $this->postJson('/api/room-types', [
            'name' => 'Garden',
            'extra_bed_cost' => 0,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 0,
            'child_sharing_limit' => 0,
            'capacity' => 2,
            'bedrooms' => 1,
            'washrooms' => 1,
            'weekday_price' => 2000,
            'weekend_price' => 2000,
            'breakfast_price' => 250,
            'child_breakfast_price' => 125,
            'adult_meal_price' => 400,
            'child_meal_price' => 200,
        ]);

        $created->assertCreated()
            ->assertJsonPath('breakfast_price', '250.00')
            ->assertJsonPath('child_breakfast_price', '125.00')
            ->assertJsonPath('adult_meal_price', '400.00')
            ->assertJsonPath('child_meal_price', '200.00');
    }
}
