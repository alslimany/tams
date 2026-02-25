# ✈️ **TAMS - Travel Agency Management System**
## **System Analysis & Architecture Documentation**

---

## 📋 **Project Overview**

**Project Name:** TAMS (Travel Agency Management System)  
**Vision:** A comprehensive SaaS platform that empowers travel agencies to connect their own airline accounts, manage bookings through a unified interface, and eventually provide additional services through a digital wallet.

**Core Value Proposition:**
- Agencies keep their existing airline accounts and credentials
- Unified interface replacing complex command-line systems
- Multi-tenant SaaS with smart subscription billing
- Extensible provider architecture (NDC, Videcom, GDS)

---

## 🎯 **Phase 1: Core Platform - Agency Enablement**

### **1.1 Multi-Tenancy Architecture**

#### **Tenant (Agency) Model**
```php
- UUID, Company Name, Registration Number, Tax ID
- Contact Information: Email, Phone, Address
- Subscription: Plan, Status, Period
- Settings: Language, Timezone, Date Format
- Status: Active, Frozen, Suspended
```

#### **User (Agent) Management**
```php
- UUID, Tenant ID, Name, Email, Role
- Status: Active/Inactive (agency can toggle)
- Activity Tracking: Last Login, Last Action
- Permissions: Role-based access control
```

#### **Tenant Isolation Strategy**
- **Database Level:** Row-level security with `tenant_id` on all tenant tables
- **Application Level:** Global scopes, middleware for tenant identification
- **Authentication:** Subdomain-based (`agency.tams.com`) or custom domain
- **Data Encryption:** Provider credentials encrypted per tenant

---

### **1.2 Subscription & Billing System**

#### **Subscription Plans**

| Plan Code | Duration | Base Seats | Max Seats | Discount |
|-----------|----------|------------|-----------|----------|
| STARTUP_M | 1 month | 3 | 10 | 0% |
| STARTUP_Q | 3 months | 3 | 10 | 10% |
| PRO_M | 1 month | 10 | 30 | 0% |
| PRO_Q | 3 months | 10 | 30 | 15% |
| PRO_Y | 12 months | 10 | 30 | 25% |
| ENTERPRISE_Y | 12 months | 30 | 100+ | 30% |

#### **Smart Billing Logic**
- **Base Price:** Includes X base seats
- **Active Users Calculation:** 
  ```sql
  SELECT COUNT(DISTINCT user_id) 
  FROM user_activity_logs 
  WHERE tenant_id = ? 
    AND activity_date BETWEEN start_date AND end_date
    AND activity_type IN ('search', 'book', 'ticket')
  ```
- **Monthly Invoice:** `Total = Base Price + (Extra Active Seats × Extra Seat Price)`
- **Seasonal Freeze:** Agency can freeze account (60 days max) - no billing, data preserved

---

### **1.3 Unified Provider Interface (Core Contract)**

#### **The Heart of the System**

```php
interface AirlineProviderInterface {
    // Flight Search
    public function search(SearchRequest $request): SearchResponse;
    
    // Fare Quote
    public function fare(string $fareId): FareResponse;
    
    // Booking Operations
    public function book(BookingRequest $request): BookingResponse;
    public function retrieveBooking(string $pnr): BookingResponse;
    
    // Ticketing
    public function issueTicket(TicketRequest $request): TicketResponse;
    public function voidTicket(string $ticketNumber): VoidResponse;
    public function refund(string $ticketNumber, string $reason): RefundResponse;
    
    // Ancillary Services
    public function getSeatMap(string $flightNumber, string $date): SeatMapResponse;
    public function getBaggageRules(string $fareBasis): BaggageResponse;
    
    // Provider Capabilities
    public function getCapabilities(): ProviderCapabilities;
    public function healthCheck(): bool;
}
```

#### **ProviderCapabilities Value Object**

```php
class ProviderCapabilities {
    public function __construct(
        // Booking Features
        public readonly bool $canSearch,
        public readonly bool $canBook,
        public readonly bool $canIssueTicket,
        public readonly bool $canRefund,
        public readonly bool $canVoid,
        
        // Ancillary Features
        public readonly bool $canSelectSeat,
        public readonly bool $canAddBaggage,
        public readonly bool $canSelectMeal,
        
        // Technical Capabilities
        public readonly bool $supportsMultiCity,
        public readonly bool $supportsHoldBooking,
        public readonly int $maxPassengersPerBooking,
        public readonly array $supportedAirlines,
        public readonly int $averageResponseTime
    ) {}
}
```

#### **Data Transfer Objects (DTOs)**

```php
// Search Request
class SearchRequest {
    public function __construct(
        public readonly string $origin,
        public readonly string $destination,
        public readonly string $departureDate,
        public readonly ?string $returnDate,
        public readonly int $adults = 1,
        public readonly int $children = 0,
        public readonly int $infants = 0,
        public readonly string $cabinClass = 'economy',
        public readonly ?string $airlineCode = null,
        public readonly array $providers = []
    ) {}
}

// Flight Option (Search Result)
class FlightOption {
    public function __construct(
        public readonly string $id,
        public readonly string $airlineCode,
        public readonly string $flightNumber,
        public readonly string $origin,
        public readonly string $destination,
        public readonly string $departureTime,
        public readonly string $arrivalTime,
        public readonly int $stops,
        public readonly Price $price,
        public readonly string $fareBasis,
        public readonly array $segments,
        public readonly array $availableServices
    ) {}
}

// Booking Response
class BookingResponse {
    public function __construct(
        public readonly string $pnr,
        public readonly string $status,
        public readonly string $provider,
        public readonly array $bookingData,
        public readonly ?string $ticketNumber = null
    ) {}
}
```

---

### **1.4 Provider Factory Pattern**

```php
class ProviderFactory {
    public static function createForTenant(
        string $tenantId,
        string $providerCode, // 'NDC', 'VIDECOM'
        string $airlineCode   // 'QR', 'EK', 'SV'
    ): AirlineProviderInterface {
        
        // 1. Fetch tenant's encrypted credentials
        $tenantProvider = TenantProvider::where([
            'tenant_id' => $tenantId,
            'provider_code' => $providerCode,
            'airline_code' => $airlineCode,
            'is_active' => true
        ])->firstOrFail();
        
        // 2. Decrypt credentials
        $credentials = decrypt($tenantProvider->encrypted_credentials);
        
        // 3. Instantiate appropriate provider
        return match($providerCode) {
            'NDC' => new NDCAirlineProvider(
                airlineCode: $airlineCode,
                apiKey: $credentials['api_key'],
                apiSecret: $credentials['api_secret']
            ),
            
            'VIDECOM' => new VidecomAirlineProvider(
                terminalId: $credentials['terminal_id'],
                userId: $credentials['user_id'],
                password: $credentials['password'],
                airlineCode: $airlineCode
            ),
            
            default => throw new \InvalidArgumentException("Unknown provider: {$providerCode}")
        };
    }
}
```

---

### **1.5 Videcom Implementation - Airline-Specific Handlers**

#### **The Challenge**
Each airline on Videcom has **different command formats** despite using the same system.

#### **Solution: Airline-Specific Handler Classes**

```
app/Infrastructure/Providers/Videcom/
├── VidecomAirlineProvider.php                 # Main Provider
├── VidecomClient.php                          # Videcom Communication
├── AirlineFactory.php                         # Airline Factory
│
├── Airlines/
│   ├── BaseVidecomAirline.php                 # Abstract Base Class
│   ├── QatarVidecomAirline.php                # QR - Specific Commands
│   ├── EmiratesVidecomAirline.php              # EK - Specific Commands
│   ├── EgyptAirVidecomAirline.php              # MS - Specific Commands
│   └── SaudiaVidecomAirline.php                # SV - Specific Commands
```

#### **Base Abstract Class**

```php
abstract class BaseVidecomAirline {
    protected string $airlineCode;
    
    // Command Building - Each airline implements differently
    abstract public function buildSearchCommand(SearchRequest $request): string;
    abstract public function buildFareCommand(string $flightLineNumber): string;
    abstract public function buildPNRCreationCommand(): string;
    abstract public function buildPassengerNameCommand(string $pnr, Passenger $passenger): string;
    abstract public function buildSegmentCommand(string $pnr, array $flightData): string;
    abstract public function buildTicketIssueCommand(string $pnr): string;
    
    // Response Parsing - Each airline has different response formats
    abstract public function parseSearchResponse(string $rawResponse): array;
    abstract public function parseFareResponse(string $rawResponse): array;
    abstract public function parsePNRResponse(string $rawResponse): string;
    
    // Capabilities per airline
    public function supports(string $feature): bool;
}
```

#### **Example: Qatar Airways Implementation**

```php
class QatarVidecomAirline extends BaseVidecomAirline {
    protected string $airlineCode = 'QR';
    
    public function buildSearchCommand(SearchRequest $request): string {
        // QR specific: AN12OCTDOHJEDQR/2
        $date = Carbon::parse($request->departureDate)->format('dMy');
        $passengers = $request->adults + $request->children;
        
        return sprintf("AN%s%s%s%s/%d", 
            $date, $request->origin, $request->destination, 
            $this->airlineCode, $passengers
        );
    }
    
    public function parseSearchResponse(string $rawResponse): array {
        // Parse QR specific response format
        // 1.QR100 Y12OCT DOHJED 0935 1235
        $flights = [];
        $lines = explode("\n", $rawResponse);
        
        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\.QR(\d+)\s+([A-Z0-9]+)\s+(\d{2}[A-Z]{3})\s+([A-Z]{3})([A-Z]{3})\s+(\d{4})\s+(\d{4})$/', $line, $matches)) {
                $flights[] = [
                    'line_number' => $matches[1],
                    'flight_number' => $matches[2],
                    'fare_basis' => $matches[3],
                    'origin' => $matches[5],
                    'destination' => $matches[6],
                    'departure_time' => $matches[7],
                    'arrival_time' => $matches[8]
                ];
            }
        }
        
        return $flights;
    }
}
```

#### **Example: Emirates Implementation**

```php
class EmiratesVidecomAirline extends BaseVidecomAirline {
    protected string $airlineCode = 'EK';
    
    public function buildSearchCommand(SearchRequest $request): string {
        // EK specific: AN12OCTDXBJEK (different format)
        $date = Carbon::parse($request->departureDate)->format('dMy');
        return sprintf("AN%s%s%s/%s",
            $date, $request->origin, $request->destination,
            $this->airlineCode
        );
    }
    
    public function buildFareCommand(string $flightLineNumber): string {
        return "FQ{$flightLineNumber}"; // EK uses FQ instead of FX
    }
    
    public function supports(string $feature): bool {
        return match($feature) {
            'seat_selection' => true,  // Emirates supports seat selection
            'meal_selection' => true,
            'hold_booking' => true,
            default => false
        };
    }
}
```

---

### **1.6 Three-Level Caching Strategy**

#### **Level 1: Shared Cache (Public Data)**
- **What:** Flight schedules, published fares, airline routes
- **Scope:** Global across all tenants
- **Key Pattern:** `flight:{airline}:{origin}:{dest}:{date}:{cabin}`
- **TTL:** 15-30 minutes
- **Tags:** `flights`, `airline:{code}`

#### **Level 2: Private Cache (Tenant Data)**
- **What:** Bookings, PNRs, issued tickets, agency reports
- **Scope:** Specific tenant only
- **Key Pattern:** `tenant:{tenant_id}:booking:{pnr}`
- **TTL:** 24 hours or until cancellation
- **Tags:** `tenant:{tenant_id}`, `bookings`

#### **Level 3: Session Cache (User Data)**
- **What:** Current search results, temporary fare quotes
- **Scope:** Specific user session
- **Key Pattern:** `search:{user_id}:{search_hash}`
- **TTL:** 30 minutes

#### **Cache Manager Implementation**

```php
class AirlineCacheManager {
    public function rememberFlights(
        SearchRequest $request, 
        Closure $callback
    ): SearchResponse {
        $key = $this->buildFlightKey($request);
        
        // Public cache for general flight data
        if ($this->isPublicCacheable($request)) {
            return Cache::tags(['flights', "airline:{$request->airlineCode}"])
                ->remember($key, 900, $callback); // 15 minutes
        }
        
        // Private cache for tenant-specific data
        return Cache::tags(["tenant:" . tenant()->id])
            ->remember($key, 3600, $callback);
    }
    
    public function rememberBooking(
        string $pnr, 
        string $tenantId, 
        Closure $callback
    ): BookingResponse {
        $key = "booking:{$pnr}";
        
        return Cache::tags(["tenant:{$tenantId}", 'bookings'])
            ->remember($key, 86400, $callback); // 24 hours
    }
}
```

---

### **1.7 Database Schema (Core Tables)**

```sql
-- Tenants (Agencies)
tenants: 
- id, uuid, name, email, tax_number
- subscription_plan_id, subscription_status
- is_frozen, frozen_until
- settings (json)

-- Users (Agents)
users:
- id, tenant_id, name, email, password
- is_active, deactivated_at
- last_login_at, last_activity_at
- permissions (json)

-- Subscription Plans
subscription_plans:
- id, code, name_ar, name_en
- duration_months, base_price
- base_seats, max_seats, extra_seat_price
- features (json), discount_percent

-- User Activity Logs
user_activity_logs:
- id, user_id, tenant_id
- activity_type, performed_at
- metadata (json)

-- Providers (System Configuration)
providers:
- id, code (VIDECOM, NDC, AMADEUS)
- name, logo, description
- capabilities (json)

-- Tenant Provider Credentials (Encrypted)
tenant_providers:
- id, tenant_id, provider_id
- airline_code, encrypted_credentials
- is_active, settings (json)
- last_used_at, success_count, failure_count

-- Bookings
bookings:
- id, uuid, tenant_id
- provider_code, airline_code, pnr
- passengers (json), flight_details (json)
- total_price, currency, status
- booked_at, ticketed_at
- raw_request, raw_response (json)

-- Tickets
tickets:
- id, booking_id, ticket_number
- issued_at, is_voided, voided_at
- refund_status, refund_amount
```

---

### **1.8 Agency Dashboard UI Components (Shadcn-ui + React)**

#### **Main Pages:**

1. **Dashboard (`/agency/dashboard`)**
   - Statistics cards: Today's bookings, issued tickets, active users
   - Recent bookings list
   - Monthly usage chart
   - Active providers status

2. **Providers Management (`/agency/providers`)**
   - List of all available providers (from system)
   - "Activate" button with credential modal
   - Active providers list with status indicators
   - Capability badges (Seat Selection, Refund, etc.)

3. **Users Management (`/agency/users`)**
   - Users table with name, email, last activity
   - Toggle switch for activate/deactivate
   - "Active this month" indicator
   - Add new user modal

4. **Booking Engine (`/booking`)**
   - Search form (Round-trip, One-way, Multi-city)
   - Flight results with airline logos
   - Price breakdown
   - Booking flow

5. **Bookings Management (`/bookings`)**
   - List with filters (status, date, airline)
   - Actions: View, Cancel, Refund, Print Ticket
   - PNR and Ticket information

#### **Multi-language Support:**
- RTL/LTR automatic switching
- react-i18next integration
- All text in translation files
- Date, number, currency formatting per locale

---

### **1.9 Testing Strategy**

#### **Coverage Requirements: 85%+**

**Unit Tests:**
- Provider Factory (100%)
- Cache Manager (100%)
- Subscription Billing Logic (100%)
- Tenant Isolation (100%)
- Each Airline Handler (100%)

**Feature Tests:**
- Agency registration and onboarding
- Provider activation with credentials
- Flight search through Videcom (mocked)
- Booking and ticket issuance
- User deactivation → not counted in billing
- Agency freeze → all users deactivated

**Integration Tests:**
- Database transactions
- Redis cache operations
- Queue jobs for async operations
- API rate limiting

**Example Test:**

```php
test('tenant can activate videcom provider for qatar airways', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs($tenant->owner);
    
    $response = $this->post("/agency/providers/videcom/activate", [
        'terminal_id' => 'TEST123',
        'user_id' => 'AGENT01',
        'password' => 'secret',
        'airline_code' => 'QR'
    ]);
    
    $response->assertStatus(200);
    
    $this->assertDatabaseHas('tenant_providers', [
        'tenant_id' => $tenant->id,
        'provider_code' => 'VIDECOM',
        'airline_code' => 'QR',
        'is_active' => true
    ]);
});
```

---

## 🚀 **Phase 1 Deliverables**

### **Sprint 1 (Weeks 1-2): Foundation**
- [x] Multi-tenancy system with tenant registration
- [x] User management (CRUD, activate/deactivate)
- [x] Basic authentication (login, logout, password reset)
- [x] Database schema with migrations

### **Sprint 2 (Weeks 3-4): Provider System**
- [x] Core Interface `AirlineProviderInterface`
- [x] Provider Factory pattern
- [x] Tenant provider credentials storage (encrypted)
- [x] Videcom client implementation
- [x] Base airline handler class

### **Sprint 3 (Weeks 5-6): First Airlines**
- [x] Qatar Airways handler (QR)
- [x] Emirates handler (EK)
- [x] Search functionality with cache
- [x] Fare quoting
- [x] Basic booking flow

### **Sprint 4 (Weeks 7-8): Booking Management**
- [x] Complete booking (PNR creation, passengers)
- [x] Ticket issuance
- [x] Booking retrieval
- [x] Cancel/void functionality
- [x] Booking list with filters

### **Sprint 5 (Weeks 9-10): Subscription & Billing**
- [x] Subscription plans CRUD
- [x] Monthly billing calculation (active users)
- [x] Invoice generation
- [x] Seasonal freeze feature
- [x] Payment integration (Stripe/any)

### **Sprint 6 (Weeks 11-12): UI Completion & Testing**
- [x] All agency dashboard pages with Shadcn-ui
- [x] RTL/LTR support complete
- [x] 85% test coverage
- [x] Performance optimization
- [x] Deployment documentation

---

## 📊 **Technical Stack Details**

| Layer | Technology | Justification |
|-------|------------|---------------|
| **Backend** | Laravel 11 | Robust ORM, queues, caching, security |
| **Frontend** | React + Inertia | SPA experience without API complexity |
| **UI Library** | Shadcn-ui | Beautiful, accessible, RTL support |
| **Database** | MySQL/PostgreSQL | ACID compliance for bookings |
| **Cache** | Redis | High-performance, multi-level caching |
| **Queue** | Laravel Horizon | Async provider communication |
| **Testing** | Pest PHP | Elegant syntax, parallel testing |
| **Payments** | Stripe/Local | Flexible payment gateways |

---

## 🔒 **Security Considerations**

1. **Credential Encryption:**
   - All provider credentials encrypted using Laravel's encryption
   - Decryption happens in-memory only
   - Never logged or stored in plain text

2. **Tenant Isolation:**
   - Global scopes on all tenant tables
   - Middleware validates tenant access
   - Subdomain/Domain validation

3. **API Security:**
   - Rate limiting per tenant
   - Request logging for audit
   - SQL injection prevention (Eloquent)

4. **Data Privacy:**
   - GDPR compliance ready
   - Data export for tenants
   - Automated data cleanup after termination

---

## 📈 **Success Metrics for Phase 1**

- **Time to First Booking:** < 5 minutes from agency registration
- **Search Response Time:** < 2 seconds (cached), < 5 seconds (live)
- **System Uptime:** 99.9%
- **Agency Onboarding Time:** < 10 minutes
- **Provider Integration Time:** < 2 hours per new airline

---

This architecture ensures:
- ✅ **Scalability** - Add 100+ airlines easily
- ✅ **Maintainability** - Each airline in separate file
- ✅ **Performance** - Multi-level caching strategy
- ✅ **Security** - Encrypted credentials, tenant isolation
- ✅ **Flexibility** - Different capabilities per provider
- ✅ **Market Fit** - Agencies keep their own accounts

The system respects the agency's existing relationships with airlines while providing a modern, unified interface that dramatically improves their operational efficiency.