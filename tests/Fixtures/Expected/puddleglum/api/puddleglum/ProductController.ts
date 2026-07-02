/* eslint-disable @typescript-eslint/no-unused-vars */
import axios, { AxiosRequestConfig } from 'axios';
import { Glum } from 'puddleglum';
import { transformToQueryString, PaginatedResponse } from 'puddleglum/utils';

export default class ProductController {
  static async index(
    request: {
      search?: string;
      include_archived?: boolean;
    } = {},
    validationOnly: boolean = false,
    fieldToValidate: string = '',
    config: AxiosRequestConfig = {},
  ) {
    return axios.get<PaginatedResponse<Puddleglum.Models.Product>>(
      `/products?${transformToQueryString(request)}`,
      {
        headers: {
          Precognition: validationOnly,
          ...(fieldToValidate
            ? { 'Precognition-Validate-Only': fieldToValidate }
            : {}),
        },
        ...config,
      },
    );
  }

  static async store(
    request: Puddleglum.Requests.StoreProductRequest = {} as Puddleglum.Requests.StoreProductRequest,
    validationOnly: boolean = false,
    fieldToValidate: string = '',
    config: AxiosRequestConfig = {},
  ) {
    return axios.post<{
      product: Puddleglum.Models.Product;
      message: string;
    }>(`/products`, request, {
      headers: {
        Precognition: validationOnly,
        ...(fieldToValidate
          ? { 'Precognition-Validate-Only': fieldToValidate }
          : {}),
      },
      ...config,
    });
  }

  static async show(
    product: string | number,
    validationOnly: boolean = false,
    fieldToValidate: string = '',
    config: AxiosRequestConfig = {},
  ) {
    return axios.get<{
      product: Puddleglum.Models.Product;
      message: string;
    }>(`/products/${product}`, {
      headers: {
        Precognition: validationOnly,
        ...(fieldToValidate
          ? { 'Precognition-Validate-Only': fieldToValidate }
          : {}),
      },
      ...config,
    });
  }

  static async destroy(
    product: string | number,
    validationOnly: boolean = false,
    fieldToValidate: string = '',
    config: AxiosRequestConfig = {},
  ) {
    return axios.delete(`/products/${product}`, {
      headers: {
        Precognition: validationOnly,
        ...(fieldToValidate
          ? { 'Precognition-Validate-Only': fieldToValidate }
          : {}),
      },
      ...config,
    });
  }
}
