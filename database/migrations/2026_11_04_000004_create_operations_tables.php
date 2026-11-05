<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('type')->default('turnover'); // turnover | restock | inspection | deep_clean
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status')->default('pending'); // pending | assigned | in_progress | for_inspection | accepted | rejected
            $t->string('priority')->default('normal');
            $t->timestamp('due_at')->nullable();
            $t->text('instructions')->nullable();
            $t->json('checklist')->nullable();
            $t->text('completion_notes')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('inspected_at')->nullable();
            $t->bigInteger('cost_cents')->nullable();
            $t->timestamps();
            $t->index(['status', 'due_at']);
            $t->index(['assigned_to', 'status']);
        });

        Schema::create('task_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('housekeeping_task_id')->constrained()->cascadeOnDelete();
            $t->string('event');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('task_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('housekeeping_task_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->unsignedInteger('byte_size')->nullable();
            $t->string('mime_type', 50)->nullable();
            $t->string('stage')->default('after'); // before | after
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('laundry_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key')->nullable()->unique();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('suggested'); // suggested | collected | processing | delivered | cancelled
            $t->unsignedSmallInteger('quantity')->nullable();
            $t->string('provider')->nullable();
            $t->date('pickup_on')->nullable();
            $t->date('delivered_on')->nullable();
            $t->bigInteger('cost_cents')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });

        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('category')->default('general');
            $t->string('severity')->default('normal'); // low | normal | high | critical
            $t->boolean('safety_block')->default(false);
            $t->string('status')->default('open'); // open | in_progress | resolved | closed
            $t->string('classification')->default('unclassified'); // unclassified | routine | capex_candidate
            $t->bigInteger('cost_cents')->nullable();
            $t->date('due_on')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['room_id', 'safety_block', 'status']);
        });

        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('charge_id')->nullable()->constrained('booking_charges')->nullOnDelete();
            $t->string('item');
            $t->text('description')->nullable();
            $t->date('discovered_on');
            $t->bigInteger('estimated_cents')->nullable();
            $t->bigInteger('approved_cents')->nullable();
            $t->string('status')->default('reported'); // reported | evidence_pending | under_review | approved_for_charge | disputed | resolved | closed
            $t->text('owner_resolution')->nullable();
            $t->bigInteger('repair_cost_cents')->nullable();
            $t->text('closure_notes')->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'discovered_on']);
        });

        Schema::create('incident_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->string('unit')->default('pc');
            $t->integer('reorder_level')->default(0);
            $t->bigInteger('unit_cost_cents')->nullable();
            $t->string('supplier')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('housekeeping_task_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type'); // receipt | issue | adjustment
            $t->integer('quantity'); // signed
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['inventory_item_id', 'property_id']);
        });

        Schema::create('parking_spaces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained()->restrictOnDelete();
            $t->string('code');
            $t->string('vendor_name')->nullable();
            $t->string('vendor_contact')->nullable(); // staff only
            $t->bigInteger('rate_cents')->nullable();
            $t->bigInteger('cost_cents')->nullable();
            $t->string('rate_status')->default('proposed'); // proposed | approved
            $t->string('restrictions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['property_id', 'code']);
        });

        Schema::create('parking_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parking_space_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('holder_name');
            $t->string('vehicle')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status')->default('active'); // active | cancelled
            $t->foreignId('charge_id')->nullable()->constrained('booking_charges')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['parking_space_id', 'start_date', 'end_date']);
        });

        Schema::create('room_secrets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('network_name')->nullable(); // encrypted
            $t->text('password')->nullable(); // encrypted
            $t->text('troubleshooting')->nullable(); // encrypted
            $t->text('contact_person')->nullable(); // encrypted
            $t->date('last_verified_on')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('full_name');
            $t->string('position')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('staff_cash_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('advance_date');
            $t->bigInteger('amount_cents');
            $t->string('note')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('staff_repayments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('repaid_on');
            $t->bigInteger('amount_cents');
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('file_name');
            $t->string('stored_path');
            $t->string('checksum', 64);
            $t->string('status')->default('staged'); // staged | committed | rolled_back
            $t->string('transformation_version');
            $t->json('summary')->nullable();
            $t->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('legacy_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $t->string('source_key')->unique(); // workbook|sheet|block|row: stable across re-imports
            $t->string('record_type'); // daily_sale | no_booking | cleaning | laundry | utility | damage
            $t->string('sheet');
            $t->unsignedInteger('row_number');
            $t->string('cell_range')->nullable();
            $t->json('raw'); // original cell values
            $t->string('room_label')->nullable();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->date('record_date')->nullable();
            $t->string('guest_name')->nullable();
            $t->string('source_label')->nullable();
            $t->string('payment_label')->nullable();
            $t->bigInteger('amount_cents')->nullable();
            $t->bigInteger('extra_cents')->nullable();
            $t->unsignedTinyInteger('confidence')->default(0);
            $t->string('review_status')->default('pending'); // pending | approved | rejected
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['import_batch_id', 'record_type', 'review_status']);
            $t->index(['record_date', 'room_id']);
        });

        Schema::create('import_anomalies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('legacy_row_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('category');
            $t->string('detail');
            $t->string('status')->default('open'); // open | resolved | dismissed
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['import_batch_id', 'category', 'status']);
        });

        Schema::create('import_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('label')->unique(); // legacy room label, normalised
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('unverified'); // unverified | confirmed | rejected
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['import_mappings', 'import_anomalies', 'legacy_rows', 'import_batches', 'staff_repayments', 'staff_cash_advances', 'employees', 'room_secrets', 'parking_assignments', 'parking_spaces', 'inventory_movements', 'inventory_items', 'incident_evidence', 'incidents', 'work_orders', 'laundry_jobs', 'task_attachments', 'task_events', 'housekeeping_tasks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
