export namespace Puddleglum.Requests {
  export interface StoreProductRequest {
    category_id: number;
    name: string;
    price: number;
    password: string;
    password_confirmation: string;
    tags?: Array<number>;
    metadata?: {
      color?: string | null;
      dimensions: {
        width: number;
        height: number;
      };
    } | null;
    images?: Array<{
      file: Blob | File;
      caption?: string | null;
    }>;
  }
}

export namespace Puddleglum.Models {
  export interface Category {
    id: number;
    name: string;
    data: string | null;
    position: number;
    created_at: string | null;
    updated_at: string | null;
    products?: Array<Puddleglum.Models.Product> | null;
    products_count?: number | null;
    readonly display_name?: any;
  }

  export interface Feature {
    id: number;
    product_id: number;
    body: string;
    created_at: string | null;
    updated_at: string | null;
    product?: Puddleglum.Models.Product | null;
  }

  export interface Product {
    id: number;
    category_id: number;
    sub_category_id: number;
    name: string;
    price: number;
    data: string | null;
    created_at: string | null;
    updated_at: string | null;
    category?: Puddleglum.Models.Category | null;
    features?: Array<Puddleglum.Models.Feature> | null;
    features_count?: number | null;
    readonly display_name?: string;
  }
}
