import React from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import TenantNavbarLayout from "@/Layouts/TenantNavbarLayout";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/Card";
import { Tabs, TabsList, TabsTrigger } from "@/Components/ui/Tabs";
import { Label } from "@/Components/ui/Label";
import { Input } from "@/Components/ui/Input";
import { Button } from "@/Components/ui/Button";
import { ChevronLeft, Plus, Minus, AlertCircle } from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";

function paxCountFromSearch(search, rateKeys) {
    const rooms = Array.isArray(search?.rooms) ? search.rooms : [];

    return rooms.map((room, index) => {
        const adults = Number(room?.adult || 1);
        const children = Array.isArray(room?.children) ? room.children : [];

        return {
            rate_key: rateKeys?.[index] ?? rateKeys?.[0] ?? "",
            paxes: [
                ...Array.from({ length: adults }, () => ({
                    civility: "Mr",
                    first_name: "",
                    last_name: "",
                    age: "",
                })),
                ...children.map((age) => ({
                    civility: "Enf",
                    first_name: "",
                    last_name: "",
                    age: Number(age || 0),
                })),
            ],
        };
    });
}

export default function HotelDetails({ bookingUuid, search, selectedOffer, rateKeys, civilityOptions = [] }) {
    const { props } = usePage();
    const flash = props.flash ?? {};
    const { t } = useTranslation();
    const [activeStep, setActiveStep] = React.useState("details");
    const currency = selectedOffer?.currency || "USD";

    const form = useForm({
        booking_uuid: bookingUuid,
        recommandations: "",
        customer: {
            first_name: "",
            last_name: "",
            email: "",
            mobile: "",
            country: "",
            city: "",
        },
        rooms: paxCountFromSearch(search, rateKeys),
    });

    const setCustomer = (key, value) => {
        form.setData("customer", { ...form.data.customer, [key]: value });
    };

    const updatePax = (roomIndex, paxIndex, key, value) => {
        form.setData(
            "rooms",
            form.data.rooms.map((room, currentRoomIndex) => {
                if (currentRoomIndex !== roomIndex) {
                    return room;
                }

                return {
                    ...room,
                    paxes: room.paxes.map((pax, currentPaxIndex) => {
                        if (currentPaxIndex !== paxIndex) {
                            return pax;
                        }

                        return { ...pax, [key]: value };
                    }),
                };
            }),
        );
    };

    const addPax = (roomIndex) => {
        form.setData(
            "rooms",
            form.data.rooms.map((room, currentRoomIndex) => {
                if (currentRoomIndex !== roomIndex) {
                    return room;
                }

                return {
                    ...room,
                    paxes: [
                        ...room.paxes,
                        {
                            civility: "Mr",
                            first_name: "",
                            last_name: "",
                            age: "",
                        },
                    ],
                };
            }),
        );
    };

    const removePax = (roomIndex, paxIndex) => {
        form.setData(
            "rooms",
            form.data.rooms.map((room, currentRoomIndex) => {
                if (currentRoomIndex !== roomIndex || room.paxes.length <= 1) {
                    return room;
                }

                return {
                    ...room,
                    paxes: room.paxes.filter(
                        (_, currentPaxIndex) => currentPaxIndex !== paxIndex,
                    ),
                };
            }),
        );
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route("hotels.book"));
    };

    // Match check_rate_rooms to the selected rate_keys (one entry per selected room)
    const selectedRateKeys = Array.isArray(rateKeys) ? rateKeys : [];
    const checkRateRooms = Array.isArray(selectedOffer?.check_rate_rooms)
        ? selectedOffer.check_rate_rooms
        : [];

    const matchedRooms = selectedRateKeys.length > 0
        ? selectedRateKeys.map((key, idx) => {
              const match = checkRateRooms.find(
                  (r) => (r.rateKey ?? r.ratekey ?? "") === key,
              );
              return match ?? checkRateRooms[idx] ?? null;
          }).filter(Boolean)
        : checkRateRooms.slice(0, 1);

    return (
        <TenantNavbarLayout>
            <Head title={t("common.complete_hotel_booking")} />

            {flash.error && (
                <div className="mx-auto max-w-7xl px-4 pt-6">
                    <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0" />
                        <p className="text-sm font-medium">{flash.error}</p>
                    </div>
                </div>
            )}

            <div className="px-4 py-8 mx-auto max-w-7xl">
                <div>
                    <Link
                        href={route("hotels.results", bookingUuid)}
                        className="mb-4 flex items-center text-sm font-bold text-muted-foreground hover:text-primary"
                    >
                        <ChevronLeft className="mr-1 h-4 w-4" />
                        {t("common.back_to_hotel_results")}
                    </Link>
                    <h2 className="text-3xl font-black tracking-tight">
                        {t("common.complete_hotel_booking")}
                    </h2>
                    <p className="mt-1 font-medium text-muted-foreground">
                        {t("common.fill_customer_guest_details")}
                    </p>
                </div>
            </div>

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8  py-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    <Tabs value={activeStep} className="w-full">
                        <TabsList className="mb-0 grid w-full grid-cols-2 rounded-2xl border bg-muted/30 p-1">
                            <TabsTrigger
                                value="details"
                                disabled
                                className="rounded-xl font-bold"
                            >
                                {t("common.guest_details_tab")}
                            </TabsTrigger>
                            <TabsTrigger
                                value="confirm"
                                disabled
                                className="rounded-xl font-bold"
                            >
                                {t("common.confirm_book_tab")}
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <Card className="overflow-hidden border-2 shadow-sm">
                        <CardHeader className="border-b bg-muted/10 pb-4">
                            <CardTitle className="text-3xl font-black tracking-tight">
                                {selectedOffer?.hotel_name}
                            </CardTitle>
                            <CardDescription>
                                {search?.check_in} — {search?.check_out} ·{" "}
                                {selectedOffer?.room_name}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <form className="space-y-6 p-6" onSubmit={submit}>
                                {activeStep === "details" && (
                                    <>
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm font-medium">
                                            {t("common.customer_information")}
                                        </div>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label>{t("common.first_name")}</Label>
                                                <Input
                                                    value={form.data.customer.first_name}
                                                    onChange={(e) => setCustomer("first_name", e.target.value)}
                                                />
                                                {form.errors["customer.first_name"] && (
                                                    <p className="text-xs text-red-600">{form.errors["customer.first_name"]}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>{t("common.last_name")}</Label>
                                                <Input
                                                    value={form.data.customer.last_name}
                                                    onChange={(e) => setCustomer("last_name", e.target.value)}
                                                />
                                                {form.errors["customer.last_name"] && (
                                                    <p className="text-xs text-red-600">{form.errors["customer.last_name"]}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>{t("common.email")}</Label>
                                                <Input
                                                    type="email"
                                                    value={form.data.customer.email}
                                                    onChange={(e) => setCustomer("email", e.target.value)}
                                                />
                                                {form.errors["customer.email"] && (
                                                    <p className="text-xs text-red-600">{form.errors["customer.email"]}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>{t("common.mobile")}</Label>
                                                <Input
                                                    value={form.data.customer.mobile}
                                                    onChange={(e) => setCustomer("mobile", e.target.value)}
                                                />
                                                {form.errors["customer.mobile"] && (
                                                    <p className="text-xs text-red-600">{form.errors["customer.mobile"]}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>{t("common.country")}</Label>
                                                <Input
                                                    value={form.data.customer.country}
                                                    onChange={(e) => setCustomer("country", e.target.value)}
                                                />
                                                {form.errors["customer.country"] && (
                                                    <p className="text-xs text-red-600">{form.errors["customer.country"]}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label>{t("common.city")}</Label>
                                                <Input
                                                    value={form.data.customer.city}
                                                    onChange={(e) => setCustomer("city", e.target.value)}
                                                />
                                                {form.errors["customer.city"] && (
                                                    <p className="text-xs text-red-600">{form.errors["customer.city"]}</p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="space-y-4 border-t pt-6">
                                            <div className="flex items-center justify-between">
                                                <h3 className="text-2xl font-black tracking-tight">
                                                    {t("common.guests")}
                                                </h3>
                                            </div>

                                            {form.data.rooms.map((room, roomIndex) => (
                                                <div
                                                    key={roomIndex}
                                                    className="space-y-4 rounded-lg border p-4"
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <h4 className="font-semibold">
                                                            {t("common.room_number", { number: roomIndex + 1 })}
                                                        </h4>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => addPax(roomIndex)}
                                                        >
                                                            <Plus className="mr-1 h-4 w-4" />
                                                            {t("common.add_guest")}
                                                        </Button>
                                                    </div>

                                                    {room.paxes.map((pax, paxIndex) => (
                                                        <div
                                                            key={paxIndex}
                                                            className="grid gap-4 rounded-md border bg-muted/10 p-3 md:grid-cols-12"
                                                        >
                                                            <div className="space-y-2 md:col-span-2">
                                                                <Label>{t("common.civility")}</Label>
                                                                <select
                                                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                                    value={pax.civility}
                                                                    onChange={(e) =>
                                                                        updatePax(roomIndex, paxIndex, "civility", e.target.value)
                                                                    }
                                                                >
                                                                    {(civilityOptions.length > 0
                                                                        ? civilityOptions
                                                                        : [
                                                                              { value: "Mr", label: "Mr" },
                                                                              { value: "Mme", label: "Mme" },
                                                                              { value: "Mlle", label: "Mlle" },
                                                                              { value: "Enf", label: "Enf" },
                                                                          ]
                                                                    ).map((opt) => (
                                                                        <option key={opt.value} value={opt.value}>
                                                                            {opt.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                            <div className="space-y-2 md:col-span-4">
                                                                <Label>{t("common.first_name")}</Label>
                                                                <Input
                                                                    value={pax.first_name}
                                                                    onChange={(e) =>
                                                                        updatePax(roomIndex, paxIndex, "first_name", e.target.value)
                                                                    }
                                                                />
                                                            </div>
                                                            <div className="space-y-2 md:col-span-4">
                                                                <Label>{t("common.last_name")}</Label>
                                                                <Input
                                                                    value={pax.last_name}
                                                                    onChange={(e) =>
                                                                        updatePax(roomIndex, paxIndex, "last_name", e.target.value)
                                                                    }
                                                                />
                                                            </div>
                                                            <div className="space-y-2 md:col-span-1">
                                                                <Label>{t("common.age")}</Label>
                                                                <Input
                                                                    type="number"
                                                                    min="0"
                                                                    max="17"
                                                                    value={pax.age}
                                                                    onChange={(e) =>
                                                                        updatePax(roomIndex, paxIndex, "age", e.target.value)
                                                                    }
                                                                    disabled={pax.civility !== "Enf"}
                                                                />
                                                            </div>
                                                            <div className="flex items-end md:col-span-1">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => removePax(roomIndex, paxIndex)}
                                                                    disabled={room.paxes.length <= 1}
                                                                >
                                                                    <Minus className="h-4 w-4" />
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            ))}
                                        </div>

                                        <div className="space-y-2 border-t pt-6">
                                            <Label>{t("common.recommendations_notes")}</Label>
                                            <Input
                                                value={form.data.recommandations}
                                                onChange={(e) => form.setData("recommandations", e.target.value)}
                                                placeholder={t("common.late_arrival_hint")}
                                            />
                                        </div>

                                        <div className="mt-8 flex items-center justify-end border-t pt-8">
                                            <Button
                                                type="button"
                                                size="lg"
                                                className="rounded-full px-10 font-black shadow-md"
                                                onClick={() => setActiveStep("confirm")}
                                            >
                                                {t("common.continue_to_review")}
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {activeStep === "confirm" && (
                                    <>
                                        <div className="rounded-xl border bg-muted/10 p-5">
                                            <p className="mb-3 text-xs font-black uppercase tracking-widest text-primary">
                                                {t("common.review_details")}
                                            </p>
                                            <div className="grid gap-4 text-sm md:grid-cols-2">
                                                <div>
                                                    <p className="text-muted-foreground">{t("common.customer")}</p>
                                                    <p className="font-bold">
                                                        {form.data.customer.first_name}{" "}
                                                        {form.data.customer.last_name}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t("common.email")}</p>
                                                    <p className="font-bold">{form.data.customer.email}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t("common.hotel")}</p>
                                                    <p className="font-bold">{selectedOffer?.hotel_name}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t("common.room")}</p>
                                                    <p className="font-bold">{selectedOffer?.room_name}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t("common.coverage")}</p>
                                                    <p className="font-bold">
                                                        {search?.check_in} — {search?.check_out}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">{t("common.guests")}</p>
                                                    <p className="font-bold">
                                                        {form.data.rooms.reduce(
                                                            (total, room) => total + room.paxes.length,
                                                            0,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mt-8 flex items-center justify-between border-t pt-8">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                className="font-bold"
                                                onClick={() => setActiveStep("details")}
                                            >
                                                <ChevronLeft className="mr-2 h-4 w-4" />
                                                {t("common.back")}
                                            </Button>
                                            <Button
                                                type="submit"
                                                size="lg"
                                                className="rounded-full bg-emerald-600 px-12 text-lg font-black text-white shadow-xl hover:bg-emerald-700"
                                                disabled={form.processing}
                                            >
                                                {form.processing
                                                    ? t("common.booking_hotel")
                                                    : t("common.confirm_book_hotel")}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <div className="hidden lg:block">
                    <div className="sticky top-8">
                        <Card className="overflow-hidden border-2 shadow-lg">
                            <div className="bg-primary p-6 text-primary-foreground">
                                <h3 className="mb-1 text-xl font-black">
                                    {t("common.offer_summary")}
                                </h3>
                                <p className="text-sm font-medium text-primary-foreground/80">
                                    {t("common.hotel_booking")}
                                </p>
                            </div>
                            <CardContent className="p-0">
                                <div className="space-y-4 border-b bg-muted/10 p-6">
                                    <div className="flex items-center justify-between gap-4 text-sm font-bold">
                                        <span className="text-muted-foreground">{t("common.hotel")}</span>
                                        <span className="text-right">{selectedOffer?.hotel_name}</span>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 text-sm font-bold">
                                        <span className="text-muted-foreground">{t("common.room")}</span>
                                        <span className="text-right">{selectedOffer?.room_name}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t("common.board")}</span>
                                        <span>{selectedOffer?.board_name || "-"}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t("common.currency")}</span>
                                        <span>{currency}</span>
                                    </div>
                                </div>

                                <div className="space-y-3 p-6">
                                    <div className="flex justify-between text-sm font-bold">
                                        <span className="text-muted-foreground">{t("common.provider_cost")}</span>
                                        <span>
                                            {Number(selectedOffer?.provider_price ?? selectedOffer?.price ?? 0).toFixed(2)}{" "}
                                            {currency}
                                        </span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                        <span>{t("common.markup_profit")}</span>
                                        <span>
                                            {Number(selectedOffer?.markup_amount ?? 0).toFixed(2)} {currency}
                                        </span>
                                    </div>
                                    <div className="flex justify-between text-sm font-medium text-muted-foreground">
                                        <span>{t("common.taxes")}</span>
                                        <span>0.00 {currency}</span>
                                    </div>

                                    {matchedRooms.map((room, index) => (
                                        <div
                                            key={room.rateKey ?? room.ratekey ?? index}
                                            className="rounded-lg border bg-muted/20 p-3 text-xs text-muted-foreground"
                                        >
                                            <p className="font-bold text-foreground">
                                                {t("common.room_number", { number: index + 1 })}: {room.name ?? selectedOffer?.room_name ?? "-"}
                                            </p>
                                            <p>
                                                {t("common.no_show")}: {Number(room.noShow ?? 0).toFixed(2)} {room.currency ?? currency}
                                            </p>
                                            {Array.isArray(room.cancellationPolicies) && room.cancellationPolicies.length > 0 && (
                                                <p>
                                                    {t("common.cancellation_from").replace(":date", room.cancellationPolicies[0]?.from ?? "-")}:{" "}
                                                    {Number(room.cancellationPolicies[0]?.amount ?? 0).toFixed(2)} {room.currency ?? currency}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>

                                <div className="flex items-end justify-between border-t bg-muted/30 p-6">
                                    <span className="font-bold text-muted-foreground">
                                        {t("common.total_to_pay")}
                                    </span>
                                    <div className="text-right">
                                        <p className="text-3xl font-black tracking-tight text-primary">
                                            {Number(selectedOffer?.price ?? 0).toFixed(2)}
                                        </p>
                                        <p className="text-xs font-black uppercase tracking-widest text-muted-foreground">
                                            {currency}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </TenantNavbarLayout>
    );
}
