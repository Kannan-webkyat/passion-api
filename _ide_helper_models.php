<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $room_id
 * @property int|null $rate_plan_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property array<array-key, mixed>|null $guest_identities
 * @property array<array-key, mixed>|null $guest_identity_types
 * @property string|null $city
 * @property string|null $country
 * @property int $adults_count
 * @property int $children_count
 * @property int $infants_count
 * @property int $extra_beds_count
 * @property int $adult_breakfast_count
 * @property int $child_breakfast_count
 * @property string $check_in
 * @property string $check_out
 * @property string|null $estimated_arrival_time
 * @property string|null $early_checkin_time
 * @property string|null $late_checkout_time
 * @property numeric $total_price
 * @property numeric $grand_total
 * @property string $payment_status
 * @property string|null $payment_method
 * @property numeric $deposit_amount
 * @property string $status
 * @property string $booking_source
 * @property string|null $source_reference
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $created_by
 * @property int|null $booking_group_id
 * @property-read \App\Models\BookingGroup|null $bookingGroup
 * @property-read \App\Models\User|null $creator
 * @property-read mixed $guest_name
 * @property-read \App\Models\Room $room
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookingSegment> $segments
 * @property-read int|null $segments_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereAdultBreakfastCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereAdultsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereBookingGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereBookingSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCheckIn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCheckOut($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereChildBreakfastCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereChildrenCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereDepositAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereEarlyCheckinTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereEstimatedArrivalTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereExtraBedsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereGrandTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereGuestIdentities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereGuestIdentityTypes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereInfantsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereLateCheckoutTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking wherePaymentStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereRatePlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereSourceReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereTotalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Booking whereUpdatedAt($value)
 */
	class Booking extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string $status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Booking> $bookings
 * @property-read int|null $bookings_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereContactPerson($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingGroup whereUpdatedAt($value)
 */
	class BookingGroup extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $booking_id
 * @property int $room_id
 * @property string $check_in
 * @property string $check_out
 * @property int|null $rate_plan_id
 * @property int $adults_count
 * @property int $children_count
 * @property int $extra_beds_count
 * @property numeric $total_price
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Booking $booking
 * @property-read \App\Models\RatePlan|null $ratePlan
 * @property-read \App\Models\Room $room
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereAdultsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereBookingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereCheckIn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereCheckOut($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereChildrenCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereExtraBedsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereRatePlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereTotalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookingSegment whereUpdatedAt($value)
 */
	class BookingSegment extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryLocation> $locations
 * @property-read int|null $locations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereUpdatedAt($value)
 */
	class Department extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GRN newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GRN newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GRN query()
 */
	class GRN extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InventoryCategory> $children
 * @property-read int|null $children_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $items
 * @property-read int|null $items_count
 * @property-read InventoryCategory|null $parent
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereUpdatedAt($value)
 */
	class InventoryCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $category_id
 * @property int|null $vendor_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property float $cost_price
 * @property int $reorder_level
 * @property int $current_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $purchase_uom_id
 * @property int|null $issue_uom_id
 * @property float $conversion_factor
 * @property int|null $tax_id
 * @property-read \App\Models\InventoryCategory|null $category
 * @property-read \App\Models\InventoryUom|null $issueUom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryLocation> $locations
 * @property-read int|null $locations_count
 * @property-read \App\Models\InventoryUom|null $purchaseUom
 * @property-read \App\Models\InventoryTax|null $tax
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryTransaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read \App\Models\Vendor|null $vendor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereConversionFactor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereCostPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereCurrentStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereIssueUomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem wherePurchaseUomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereReorderLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereTaxId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem whereVendorId($value)
 */
	class InventoryItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $department_id
 * @property string $name
 * @property string $type
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Department|null $department
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $items
 * @property-read int|null $items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLocation whereUpdatedAt($value)
 */
	class InventoryLocation extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property numeric $rate
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $type
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $items
 * @property-read int|null $items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax whereRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTax whereUpdatedAt($value)
 */
	class InventoryTax extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $inventory_item_id
 * @property int|null $inventory_location_id
 * @property string $type
 * @property int $quantity
 * @property int|null $department_id
 * @property \App\Models\Department|null $department
 * @property string|null $reason
 * @property int|null $user_id
 * @property string|null $notes
 * @property string $transaction_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryItem $item
 * @property-read \App\Models\InventoryLocation|null $location
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereDepartment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereInventoryItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereInventoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereTransactionDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransaction whereUserId($value)
 */
	class InventoryTransaction extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $short_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $issueItems
 * @property-read int|null $issue_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $purchaseItems
 * @property-read int|null $purchase_items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom whereShortName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryUom whereUpdatedAt($value)
 */
	class InventoryUom extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property int $is_active
 * @property int $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereUpdatedAt($value)
 */
	class PaymentMethod extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUpdatedAt($value)
 */
	class Permission extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $po_number
 * @property int $vendor_id
 * @property int|null $location_id
 * @property string $order_date
 * @property string|null $expected_delivery_date
 * @property string|null $received_at
 * @property string $status
 * @property numeric $subtotal
 * @property numeric $tax_amount
 * @property string $payment_status
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property numeric $total_amount
 * @property numeric $paid_amount
 * @property string|null $paid_at
 * @property string|null $notes
 * @property string|null $received_document_path
 * @property string|null $invoice_path
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseOrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\InventoryLocation|null $location
 * @property-read \App\Models\Vendor $vendor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereExpectedDeliveryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereInvoicePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereOrderDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePaidAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePaymentReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePaymentStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePoNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereReceivedDocumentPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereTaxAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereVendorId($value)
 */
	class PurchaseOrder extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $inventory_item_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property numeric $unit_price
 * @property numeric $tax_rate
 * @property numeric $tax_amount
 * @property numeric $total_amount
 * @property numeric $subtotal
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryItem $inventoryItem
 * @property-read \App\Models\PurchaseOrder $purchaseOrder
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereInventoryItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem wherePurchaseOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereQuantityOrdered($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereQuantityReceived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereTaxAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereTaxRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereUpdatedAt($value)
 */
	class PurchaseOrderItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $room_type_id
 * @property string $name
 * @property numeric $base_price
 * @property bool $includes_breakfast
 * @property bool $is_active
 * @property array<array-key, mixed>|null $price_modifiers
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\RoomType $roomType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereBasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereIncludesBreakfast($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan wherePriceModifiers($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereRoomTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatePlan whereUpdatedAt($value)
 */
	class RatePlan extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 */
	class Role extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $room_number
 * @property int $room_type_id
 * @property string|null $status
 * @property string|null $floor
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Booking> $bookings
 * @property-read int|null $bookings_count
 * @property-read \App\Models\RoomType $roomType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookingSegment> $segments
 * @property-read int|null $segments_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereFloor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereRoomNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereRoomTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereUpdatedAt($value)
 */
	class Room extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property numeric $base_price
 * @property numeric $breakfast_price
 * @property numeric $child_breakfast_price
 * @property int $base_occupancy
 * @property numeric $extra_bed_cost
 * @property int $capacity
 * @property int $extra_bed_capacity
 * @property int $child_sharing_limit
 * @property int|null $tax_id
 * @property string|null $bed_config
 * @property array<array-key, mixed>|null $amenities
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RatePlan> $ratePlans
 * @property-read int|null $rate_plans_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms
 * @property-read int|null $rooms_count
 * @property-read \App\Models\InventoryTax|null $tax
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereAmenities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereBaseOccupancy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereBasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereBedConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereBreakfastPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereChildBreakfastPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereChildSharingLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereExtraBedCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereExtraBedCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereTaxId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereUpdatedAt($value)
 */
	class RoomType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $request_number
 * @property int $from_location_id
 * @property int $to_location_id
 * @property int|null $department_id
 * @property string|null $required_date
 * @property int $requested_by
 * @property int|null $approved_by
 * @property string $status
 * @property string|null $notes
 * @property string $requested_at
 * @property string|null $approved_at
 * @property string|null $issued_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $approver
 * @property-read \App\Models\Department|null $department
 * @property-read \App\Models\InventoryLocation $fromLocation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StoreRequestItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $requester
 * @property-read \App\Models\InventoryLocation $toLocation
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereFromLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereIssuedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereRequestNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereRequestedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereRequiredDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereToLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequest whereUpdatedAt($value)
 */
	class StoreRequest extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $store_request_id
 * @property int $inventory_item_id
 * @property numeric $quantity_requested
 * @property numeric $quantity_issued
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryItem $item
 * @property-read \App\Models\StoreRequest $storeRequest
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereInventoryItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereQuantityIssued($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereQuantityRequested($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereStoreRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoreRequestItem whereUpdatedAt($value)
 */
	class StoreRequestItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Department> $departments
 * @property-read int|null $departments_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $gstin
 * @property string|null $pan
 * @property string|null $state
 * @property bool $is_registered_dealer
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $items
 * @property-read int|null $items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereContactPerson($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereGstin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereIsRegisteredDealer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor wherePan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor whereUpdatedAt($value)
 */
	class Vendor extends \Eloquent {}
}

